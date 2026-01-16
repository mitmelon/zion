<?php
namespace ZionXMemory\Adaptive;

use ZionXMemory\Contracts\MemoryPriorityInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;

/**
 * ATLASPriority - Optimal long-term memory prioritization
 * Inspired by ATLAS: learns to optimize context streaming
 * Balances current relevance with historical importance
 * 
 * @package ZionXMemory\Adaptive
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

class ATLASPriority implements MemoryPriorityInterface {
    private StorageAdapterInterface $storage;
    private array $config;
    private ?AIAdapterInterface $ai;
    
    public function __construct(StorageAdapterInterface $storage, array $config = []) {
        $this->storage = $storage;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Optional AI integration: pass an AI adapter to improve epistemic checks
     */
    public function setAIAdapter(AIAdapterInterface $ai): void {
        $this->ai = $ai;
    }
    
    /**
     * Calculate memory priority for retrieval
     * ATLAS-style optimization: balance recency, relevance, importance
     */
    public function calculatePriority(array $memoryUnit, array $queryContext): float {
        $scores = [
            'relevance' => $this->calculateRelevance($memoryUnit, $queryContext),
            'recency' => $this->calculateRecency($memoryUnit),
            'importance' => $memoryUnit['importance'] ?? 0.5,
            'surprise' => $memoryUnit['surprise_score'] ?? 0.5,
            'usage' => $this->calculateUsageScore($memoryUnit),
            'context_fit' => $this->calculateContextFit($memoryUnit, $queryContext)
        ];
        
        // Adaptive weighted combination
        $weights = $this->adaptWeights($queryContext);
        
        $priority = 0.0;
        foreach ($scores as $factor => $score) {
            $priority += $weights[$factor] * $score;
        }
        
        return min(1.0, $priority);
    }
    
    /**
     * Rerank memories by importance
     * Implements ATLAS-style representational capacity optimization
     */
    public function rerankByImportance(array $memories, array $criteria): array {
        $tokenBudget = $criteria['token_budget'] ?? 8000;
        $queryContext = $criteria['query_context'] ?? [];
        $diversityFactor = $criteria['diversity_factor'] ?? 0.3;
        
        // Calculate priority for each memory
        $scored = [];
        foreach ($memories as $memory) {
            $priority = $this->calculatePriority($memory, $queryContext);
            $scored[] = [
                'memory' => $memory,
                'priority' => $priority,
                'tokens' => $this->estimateTokens($memory)
            ];
        }
        
        // Sort by priority
        usort($scored, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        // Apply diversity-aware selection
        $selected = $this->diversityAwareSelection($scored, $tokenBudget, $diversityFactor);
        
        return array_map(fn($s) => $s['memory'], $selected);
    }
    
    /**
     * Get top-k most important memories
     * Efficient retrieval using priority indices
     */
    public function getTopKImportant(string $tenantId, int $k, array $filters): array {
        // Use surprise and importance indices for efficient retrieval
        $candidates = $this->getCandidatesFromIndices($tenantId, $filters);
        
        // Score and sort
        $scored = array_map(function($memory) use ($filters) {
            $queryContext = $filters['query_context'] ?? [];
            return [
                'memory' => $memory,
                'priority' => $this->calculatePriority($memory, $queryContext)
            ];
        }, $candidates);
        
        usort($scored, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        // Return top k
        return array_map(
            fn($s) => $s['memory'],
            array_slice($scored, 0, $k)
        );
    }
    
    /**
     * Update importance scores based on usage patterns
     * ATLAS-style learning from access patterns
     */
    public function updateImportanceFromUsage(string $tenantId, array $accessLog): void {
        $learningRate = $this->config['learning_rate'];
        
        foreach ($accessLog as $access) {
            $memoryId = $access['memory_id'];
            $key = "adaptive_memory:{$tenantId}:{$memoryId}";
            $memory = $this->storage->read($key);
            
            if (!$memory) continue;
            
            // Update based on access patterns
            $currentImportance = $memory['importance'] ?? 0.5;
            $accessUtility = $access['utility'] ?? 0.5; // How useful was this memory?
            
            // Exponential moving average update
            $newImportance = (1 - $learningRate) * $currentImportance + 
                           $learningRate * $accessUtility;
            
            $memory['importance'] = $newImportance;
            $memory['access_count'] = ($memory['access_count'] ?? 0) + 1;
            $memory['last_access'] = time();
            
            $this->storage->write($key, $memory, ['tenant' => $tenantId]);
        }
    }
    
    /**
     * Calculate relevance to query context
     */
    private function calculateRelevance(array $memoryUnit, array $queryContext): float {
        if (empty($queryContext)) {
            return 0.5;
        }
        
        $memoryText = $this->extractText($memoryUnit);
        $queryText = $this->extractText($queryContext);
        
        // Semantic similarity (simplified)
        $similarity = $this->calculateTextSimilarity($memoryText, $queryText);
        
        return $similarity;
    }
    
    /**
     * Calculate recency score
     */
    private function calculateRecency(array $memoryUnit): float {
        $age = time() - ($memoryUnit['timestamp'] ?? time());
        $ageInDays = $age / 86400;
        
        // Exponential decay with configurable half-life
        $halfLife = $this->config['recency_half_life_days'];
        return exp(-0.693 * $ageInDays / $halfLife);
    }
    
    /**
     * Calculate usage score
     */
    private function calculateUsageScore(array $memoryUnit): float {
        $accessCount = $memoryUnit['access_count'] ?? 0;
        $lastAccess = $memoryUnit['last_access'] ?? 0;
        
        // Frequency component
        $frequency = min(1.0, log(1 + $accessCount) / log(50));
        
        // Recency of last access
        $recency = time() - $lastAccess;
        $recencyScore = 1.0 / (1 + $recency / 86400); // Days since last access
        
        return (0.6 * $frequency) + (0.4 * $recencyScore);
    }
    
    /**
     * Calculate context fit
     * How well does this memory integrate with current context?
     */
    private function calculateContextFit(array $memoryUnit, array $queryContext): float {
        if (empty($queryContext)) {
            return 0.5;
        }
        
        // Check temporal coherence
        $temporalFit = $this->calculateTemporalCoherence($memoryUnit, $queryContext);
        
        // Check epistemic coherence (no contradictions with accepted beliefs)
        $epistemicFit = $this->calculateEpistemicCoherence($memoryUnit, $queryContext);
        
        return (0.5 * $temporalFit) + (0.5 * $epistemicFit);
    }
    
    /**
     * Adapt weights based on query context
     * ATLAS-style dynamic weight adjustment
     */
    private function adaptWeights(array $queryContext): array {
        $defaultWeights = [
            'relevance' => 0.25,
            'recency' => 0.20,
            'importance' => 0.20,
            'surprise' => 0.15,
            'usage' => 0.10,
            'context_fit' => 0.10
        ];
        
        // Adjust weights based on query type
        if (isset($queryContext['query_type'])) {
            switch ($queryContext['query_type']) {
                case 'recent':
                    $defaultWeights['recency'] = 0.40;
                    $defaultWeights['relevance'] = 0.30;
                    break;
                    
                case 'important':
                    $defaultWeights['importance'] = 0.35;
                    $defaultWeights['surprise'] = 0.25;
                    break;
                    
                case 'novel':
                    $defaultWeights['surprise'] = 0.40;
                    $defaultWeights['relevance'] = 0.30;
                    break;
            }
        }
        
        // Normalize
        $sum = array_sum($defaultWeights);
        return array_map(fn($w) => $w / $sum, $defaultWeights);
    }
    
    /**
     * Diversity-aware selection
     * Ensures diverse memories, not just top-scoring duplicates
     */
    private function diversityAwareSelection(array $scored, int $tokenBudget, float $diversityFactor): array {
        $selected = [];
        $usedTokens = 0;
        $seenTopics = [];
        
        foreach ($scored as $item) {
            $tokens = $item['tokens'];
            
            if ($usedTokens + $tokens > $tokenBudget) {
                break;
            }
            
            // Check diversity
            $topic = $this->extractTopic($item['memory']);
            $diversityPenalty = isset($seenTopics[$topic]) ? 
                $diversityFactor * $seenTopics[$topic] : 0;
            
            $adjustedPriority = $item['priority'] * (1 - $diversityPenalty);
            
            if ($adjustedPriority > 0.3) { // Minimum threshold
                $selected[] = $item;
                $usedTokens += $tokens;
                $seenTopics[$topic] = ($seenTopics[$topic] ?? 0) + 1;
            }
        }
        
        return $selected;
    }
    
    /**
     * Get candidates from priority indices
     */
    private function getCandidatesFromIndices(string $tenantId, array $filters): array {
        $pattern = "adaptive_memory:{$tenantId}:*";
        $allMemories = $this->storage->query(['pattern' => $pattern]);
        
        // Apply basic filters
        return array_filter($allMemories, function($memory) use ($filters) {
            if (isset($filters['min_surprise']) && 
                ($memory['surprise_score'] ?? 0) < $filters['min_surprise']) {
                return false;
            }
            
            if (isset($filters['layer']) && $memory['layer'] !== $filters['layer']) {
                return false;
            }
            
            return true;
        });
    }
    
    private function calculateTextSimilarity(string $text1, string $text2): float {
        $words1 = array_unique(str_word_count(strtolower($text1), 1));
        $words2 = array_unique(str_word_count(strtolower($text2), 1));
        
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));
        
        return $union > 0 ? $intersection / $union : 0.0;
    }
    
    private function calculateTemporalCoherence(array $memory, array $context): float {
        // More robust temporal coherence:
        // - Prefer time ranges if present (start/end)
        // - Compute overlap ratio when both ranges available
        // - Apply exponential decay based on midpoint distance using a half-life
        $now = time();

        $memStart = $memory['timestamp'] ?? ($memory['time_range']['start'] ?? null) ?? null;
        $memEnd = $memory['time_range']['end'] ?? $memStart ?? null;

        $ctxStart = $context['timestamp'] ?? ($context['time_range']['start'] ?? null) ?? null;
        $ctxEnd = $context['time_range']['end'] ?? $ctxStart ?? null;

        $halfLifeDays = $this->config['temporal_half_life_days'] ?? 30;

        // If we have both start and end for memory and context, compute overlap
        if ($memStart !== null && $memEnd !== null && $ctxStart !== null && $ctxEnd !== null) {
            $overlapStart = max($memStart, $ctxStart);
            $overlapEnd = min($memEnd, $ctxEnd);
            $overlap = max(0, $overlapEnd - $overlapStart);

            $unionStart = min($memStart, $ctxStart);
            $unionEnd = max($memEnd, $ctxEnd);
            $unionSpan = max(1, $unionEnd - $unionStart);

            $overlapRatio = $overlap / $unionSpan; // 0..1

            $memMid = ($memStart + $memEnd) / 2.0;
            $ctxMid = ($ctxStart + $ctxEnd) / 2.0;
            $midDiffDays = abs($memMid - $ctxMid) / 86400.0;

            $decay = exp(-0.693 * $midDiffDays / max(1, $halfLifeDays));

            // Blend overlap and decay: overlap is stronger signal, decay moderates it
            $coherence = 0.2 + 0.8 * (0.75 * $overlapRatio + 0.25 * $decay);
        } else {
            // Fallback to midpoint distance if ranges missing
            $memPoint = $memStart ?? ($memory['timestamp'] ?? $now);
            $ctxPoint = $ctxStart ?? ($context['timestamp'] ?? $now);

            $deltaDays = abs($memPoint - $ctxPoint) / 86400.0;
            $coherence = exp(-0.693 * $deltaDays / max(1, $halfLifeDays));
        }

        // Clamp
        return max(0.0, min(1.0, (float) $coherence));
    }
    
    private function calculateEpistemicCoherence(array $memory, array $context): float {
        // Attempt to use AI when available for more accurate epistemic checks.
        $claims = $memory['content']['claims'] ?? $memory['claims'] ?? [];
        $beliefs = $context['accepted_beliefs'] ?? $context['beliefs'] ?? [];

        if (empty($claims)) {
            return 0.9;
        }

        // If AI adapter is provided, prefer it for contradiction detection
        if (!empty($beliefs) && isset($this->ai) && $this->ai !== null) {
            try {
                $totalPenalty = 0.0;
                $totalAgreement = 0.0;
                $count = 0;

                foreach ($claims as $claim) {
                    $claimPenalty = 0.0;
                    $claimAgreement = 0.0;
                    $seen = 0;

                    foreach ($beliefs as $belief) {
                        $seen++;
                        $res = null;
                        try {
                            $res = $this->ai->detectContradiction((string)$claim, (string)$belief);
                        } catch (\Throwable $e) {
                            $res = null;
                        }

                        if ($res === true) {
                            // strong contradiction
                            $claimPenalty += 1.0;
                            continue;
                        } elseif ($res === false) {
                            // explicit non-contradiction -> agreement
                            $claimAgreement += 0.6;
                            continue;
                        }

                        // When AI couldn't decide, ask for epistemic confidence
                        try {
                            $score = $this->ai->scoreEpistemicConfidence((string)$claim, ['context' => $belief]);
                        } catch (\Throwable $e) {
                            $score = null;
                        }

                        $conf = 0.5;
                        if (is_array($score)) {
                            if (isset($score['mean'])) $conf = (float)$score['mean'];
                            elseif (isset($score['confidence'])) $conf = (float)$score['confidence'];
                        }

                        if ($conf < 0.45) {
                            $claimPenalty += (1.0 - $conf);
                        } else {
                            $claimAgreement += 0.3 * $conf;
                        }
                    }

                    if ($seen > 0) {
                        $totalPenalty += $claimPenalty / $seen;
                        $totalAgreement += $claimAgreement / $seen;
                        $count++;
                    }
                }

                if ($count === 0) return 0.8;

                $avgAgreement = $totalAgreement / $count;
                $avgPenalty = $totalPenalty / $count;
                $coherence = 0.5 + $avgAgreement - $avgPenalty;

                return max(0.0, min(1.0, (float)$coherence));
            } catch (\Throwable $e) {
                // Fall through to heuristic if AI fails
            }
        }

        // Fallback heuristic (non-AI) path
        if (empty($beliefs)) {
            $memText = $this->extractText($memory);
            $ctxText = $this->extractText($context);
            $sim = $this->calculateTextSimilarity($memText, $ctxText);
            return max(0.0, min(1.0, 0.6 + 0.4 * $sim));
        }

        $totalPenalty = 0.0;
        $totalAgreement = 0.0;
        $count = 0;

        foreach ($claims as $claim) {
            $bestSim = 0.0;
            $bestBelief = null;

            foreach ($beliefs as $belief) {
                $sim = $this->calculateTextSimilarity($claim, $belief);
                if ($sim > $bestSim) {
                    $bestSim = $sim;
                    $bestBelief = $belief;
                }
            }

            $confidence = $memory['confidence']['mean'] ?? $memory['confidence'] ?? 0.5;
            $confidence = is_numeric($confidence) ? (float)$confidence : 0.5;

            if ($bestSim >= 0.75) {
                $claimNeg = $this->textHasNegation($claim);
                $beliefNeg = $this->textHasNegation((string)$bestBelief);

                if ($claimNeg !== $beliefNeg) {
                    $penalty = 0.7 * $bestSim * (1.0 - $confidence);
                    $totalPenalty += $penalty;
                } else {
                    $agreement = 0.4 * $bestSim * $confidence;
                    $totalAgreement += $agreement;
                }
            } elseif ($bestSim >= 0.4) {
                $totalAgreement += 0.2 * $bestSim * $confidence;
            } else {
                $totalPenalty += 0.05 * (1.0 - $bestSim);
            }

            $count++;
        }

        if ($count === 0) {
            return 0.8;
        }

        $avgAgreement = $totalAgreement / $count;
        $avgPenalty = $totalPenalty / $count;

        $coherence = 0.5 + $avgAgreement - $avgPenalty;

        return max(0.0, min(1.0, (float)$coherence));
    }
    
    private function extractText($data): string {
        if (is_string($data)) return $data;
        if (isset($data['content'])) return $this->extractText($data['content']);
        if (isset($data['text'])) return $data['text'];
        return json_encode($data);
    }
    
    private function extractTopic(array $memory): string {
        // Simplified topic extraction
        $text = $this->extractText($memory);
        $words = str_word_count(strtolower($text), 1);
        return !empty($words) ? $words[0] : 'unknown';
    }

    /**
     * Lightweight negation detector
     */
    private function textHasNegation(string $text): bool {
        $negationTokens = [" not ", " no ", " never ", "n't ", " cannot ", " can't ", " without ", " none "];
        $lower = ' ' . strtolower($text) . ' ';
        foreach ($negationTokens as $tok) {
            if (strpos($lower, $tok) !== false) return true;
        }
        return false;
    }
    
    private function estimateTokens(array $memory): int {
        $text = $this->extractText($memory);
        return (int) ceil(strlen($text) / 4);
    }
    
    private function getDefaultConfig(): array {
        return [
            'recency_half_life_days' => 7,
            'learning_rate' => 0.1,
            'temporal_half_life_days' => 30,
            'temporal_max_window_days' => 365
        ];
    }
}