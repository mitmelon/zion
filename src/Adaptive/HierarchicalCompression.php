<?php
namespace ZionXMemory\Adaptive;

use ZionXMemory\Contracts\HierarchicalCompressionInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Utils\MDLCompressor;

/**
 * HierarchicalCompression - Multi-level surprise-aware compression
 * Implements ResFormer/Reservoir-inspired memory compression
 * with surprise-based preservation
 * 
 * @package ZionXMemory\Adaptive
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

class HierarchicalCompression implements HierarchicalCompressionInterface {
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    private MDLCompressor $mdl;
    
    const LEVEL_FULL = 0;        // No compression
    const LEVEL_LIGHT = 1;       // 70% of original
    const LEVEL_MEDIUM = 2;      // 40% of original
    const LEVEL_HEAVY = 3;       // 20% of original
    const LEVEL_EXTREME = 4;     // 10% of original (summary only)
    
    public function __construct(
        StorageAdapterInterface $storage,
        AIAdapterInterface $ai
    ) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->mdl = new MDLCompressor();
    }
    
    /**
     * Compress memory unit to target level
     * Preserves high-surprise content preferentially
     */
    public function compress(
        array $memoryUnit,
        int $targetLevel,
        array $preservationCriteria
    ): array {
        if ($targetLevel === self::LEVEL_FULL) {
            return $memoryUnit; // No compression
        }
        
        $content = $this->extractContent($memoryUnit);
        $surpriseScore = $memoryUnit['surprise_score'] ?? 0.5;
        
        // Calculate preservation requirements
        $preservationScore = $this->calculatePreservationScore($memoryUnit, $preservationCriteria);
        
        // Determine compression strategy
        $strategy = $this->selectCompressionStrategy($targetLevel, $surpriseScore, $preservationScore);
        
        // Compress content
        $compressed = $this->applyCompression($content, $strategy, $preservationCriteria);
        
        // Build compressed memory unit
        $compressedUnit = $memoryUnit;
        $compressedUnit['content'] = $compressed['content'];
        $compressedUnit['compression_level'] = $targetLevel;
        $compressedUnit['compression_strategy'] = $strategy;
        $compressedUnit['compression_ratio'] = $compressed['ratio'];
        $compressedUnit['compressed_at'] = time();
        $compressedUnit['preserved_elements'] = $compressed['preserved'];
        
        // Store original reference for decompression
        $compressedUnit['original_ref'] = $this->storeOriginal($memoryUnit);
        
        return $compressedUnit;
    }
    
    /**
     * Create hierarchical summary with surprise awareness
     * High-surprise elements get more detail
     */
    public function createHierarchicalSummary(array $memories, array $surpriseScores): array {
        // Sort memories by surprise score
        $sorted = [];
        foreach ($memories as $i => $memory) {
            $sorted[] = [
                'memory' => $memory,
                'surprise' => $surpriseScores[$i] ?? 0.5
            ];
        }
        usort($sorted, fn($a, $b) => $b['surprise'] <=> $a['surprise']);
        
        // Build hierarchical levels
        $hierarchy = [
            'level_0' => [], // High surprise - full detail
            'level_1' => [], // Medium surprise - moderate detail
            'level_2' => [], // Low surprise - summary only
            'level_3' => []  // Very low surprise - minimal mention
        ];
        
        foreach ($sorted as $item) {
            $surprise = $item['surprise'];
            $memory = $item['memory'];
            
            if ($surprise >= 0.7) {
                $hierarchy['level_0'][] = $memory;
            } elseif ($surprise >= 0.5) {
                $hierarchy['level_1'][] = $this->compress($memory, self::LEVEL_LIGHT, []);
            } elseif ($surprise >= 0.3) {
                $hierarchy['level_2'][] = $this->compress($memory, self::LEVEL_MEDIUM, []);
            } else {
                $hierarchy['level_3'][] = $this->compress($memory, self::LEVEL_HEAVY, []);
            }
        }
        
        // Create composite summary
        $compositeSummary = $this->buildCompositeSummary($hierarchy);
        
        return [
            'hierarchy' => $hierarchy,
            'composite_summary' => $compositeSummary,
            'total_memories' => count($memories),
            'created_at' => time()
        ];
    }
    
    /**
     * Decompress memory unit
     * Retrieves original if available
     */
    public function decompress(string $tenantId, string $memoryUnitId): array {
        $key = "adaptive_memory:{$tenantId}:{$memoryUnitId}";
        $compressed = $this->storage->read($key);
        
        if (!$compressed) {
            throw new \Exception("Memory unit not found");
        }
        
        // If not compressed, return as-is
        if (!isset($compressed['compression_level']) || $compressed['compression_level'] === self::LEVEL_FULL) {
            return $compressed;
        }
        
        // Retrieve original
        $originalRef = $compressed['original_ref'] ?? null;
        if ($originalRef) {
            $original = $this->storage->read($originalRef);
            if ($original) {
                return $original;
            }
        }
        
        // If original not available, return compressed (can't fully decompress)
        return $compressed;
    }
    
    /**
     * Get compression ratio statistics
     */
    public function getCompressionRatio(string $tenantId): array {
        $pattern = "adaptive_memory:{$tenantId}:*";
        $allMemories = $this->storage->query(['pattern' => $pattern]);
        
        $stats = [
            'total' => count($allMemories),
            'by_level' => [],
            'total_original_size' => 0,
            'total_compressed_size' => 0
        ];
        
        foreach ($allMemories as $memory) {
            $level = $memory['compression_level'] ?? self::LEVEL_FULL;
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
            
            if (isset($memory['compression_ratio'])) {
                $originalSize = strlen(json_encode($memory));
                $compressedSize = $originalSize * $memory['compression_ratio'];
                
                $stats['total_original_size'] += $originalSize;
                $stats['total_compressed_size'] += $compressedSize;
            }
        }
        
        $stats['overall_ratio'] = $stats['total_original_size'] > 0 ?
            $stats['total_compressed_size'] / $stats['total_original_size'] : 1.0;
        
        return $stats;
    }
    
    /**
     * Calculate preservation score
     */
    private function calculatePreservationScore(array $memory, array $criteria): float {
        $scores = [];
        
        // Surprise preservation
        $scores[] = $memory['surprise_score'] ?? 0.5;
        
        // Contradiction preservation
        if (($memory['contradiction_count'] ?? 0) > 0) {
            $scores[] = 0.8;
        }
        
        // Evidence preservation
        if (count($memory['evidence'] ?? []) > 3) {
            $scores[] = 0.7;
        }
        
        // Criteria-based preservation
        if (isset($criteria['preserve_high_confidence'])) {
            $confidence = $memory['confidence']['mean'] ?? 0.5;
            if ($confidence > 0.8) {
                $scores[] = 0.9;
            }
        }
        
        return !empty($scores) ? max($scores) : 0.5;
    }
    
    /**
     * Select compression strategy
     */
    private function selectCompressionStrategy(
        int $targetLevel,
        float $surpriseScore,
        float $preservationScore
    ): string {
        $preservationNeed = max($surpriseScore, $preservationScore);
        
        // High preservation need = more careful compression
        if ($preservationNeed > 0.7) {
            return match($targetLevel) {
                self::LEVEL_LIGHT => 'selective_detail',
                self::LEVEL_MEDIUM => 'key_points',
                self::LEVEL_HEAVY => 'core_summary',
                self::LEVEL_EXTREME => 'minimal_reference',
                default => 'none'
            };
        } else {
            return match($targetLevel) {
                self::LEVEL_LIGHT => 'standard_reduction',
                self::LEVEL_MEDIUM => 'aggressive_reduction',
                self::LEVEL_HEAVY => 'extreme_reduction',
                self::LEVEL_EXTREME => 'reference_only',
                default => 'none'
            };
        }
    }
    
    /**
     * Apply compression using AI
     */
    private function applyCompression(
        string $content,
        string $strategy,
        array $criteria
    ): array {
        $targetRatio = $this->getTargetRatio($strategy);
        
        // Use AI for intelligent compression
        $compressedContent = $this->ai->summarize($content, [
            'target_compression' => $targetRatio,
            'strategy' => $strategy,
            'preserve_contradictions' => $criteria['preserve_contradictions'] ?? true,
            'preserve_evidence' => $criteria['preserve_evidence'] ?? true,
            'preserve_intent' => $criteria['preserve_intent'] ?? true
        ]);
        
        $actualRatio = strlen($compressedContent) / strlen($content);
        
        // Extract preserved elements
        $preserved = $this->extractPreservedElements($content, $compressedContent);
        
        return [
            'content' => $compressedContent,
            'ratio' => $actualRatio,
            'preserved' => $preserved
        ];
    }
    
    /**
     * Get target compression ratio for strategy
     */
    private function getTargetRatio(string $strategy): float {
        return match($strategy) {
            'selective_detail', 'standard_reduction' => 0.7,
            'key_points', 'aggressive_reduction' => 0.4,
            'core_summary', 'extreme_reduction' => 0.2,
            'minimal_reference', 'reference_only' => 0.1,
            default => 1.0
        };
    }
    
    /**
     * Store original for decompression
     */
    private function storeOriginal(array $memoryUnit): string {
        $originalId = $memoryUnit['id'] . '_original';
        $key = "original_memory:{$memoryUnit['tenant_id']}:{$originalId}";
        
        $this->storage->write($key, $memoryUnit, [
            'tenant' => $memoryUnit['tenant_id'],
            'type' => 'original_backup'
        ]);
        
        return $key;
    }
    
    /**
     * Extract preserved elements
     */
    private function extractPreservedElements(string $original, string $compressed): array {
        // Analyze what was preserved
        return [
            'key_phrases' => $this->extractKeyPhrases($compressed),
            'entities' => $this->extractEntities($compressed),
            'claims' => $this->extractClaims($compressed)
        ];
    }
    
    /**
     * Build composite summary from hierarchy
     */
    private function buildCompositeSummary(array $hierarchy): string {
        $parts = [];
        
        // High detail section
        if (!empty($hierarchy['level_0'])) {
            $parts[] = "High-priority memories (full detail):\n" . 
                      $this->summarizeLevel($hierarchy['level_0']);
        }
        
        // Medium detail section
        if (!empty($hierarchy['level_1'])) {
            $parts[] = "Important memories (summary):\n" . 
                      $this->summarizeLevel($hierarchy['level_1']);
        }
        
        // Low detail section
        if (!empty($hierarchy['level_2'])) {
            $parts[] = "Background context:\n" . 
                      $this->summarizeLevel($hierarchy['level_2']);
        }
        
        return implode("\n\n", $parts);
    }
    
    private function summarizeLevel(array $memories): string {
        $summaries = array_map(fn($m) => $this->extractContent($m), $memories);
        return implode("\n", array_slice($summaries, 0, 10));
    }
    
    private function extractContent(array $memory): string {
        if (isset($memory['content'])) {
            return is_string($memory['content']) ? $memory['content'] : json_encode($memory['content']);
        }
        return '';
    }
    
    private function extractKeyPhrases(string $text): array {
        // Prefer AI-based entity extraction when available
        $candidates = [];
        if (isset($this->ai) && $this->ai !== null) {
            try {
                $ents = $this->ai->extractEntities($text);
                if (is_array($ents) && !empty($ents)) {
                    foreach ($ents as $e) {
                        if (is_string($e) && strlen($e) > 1) $candidates[] = $e;
                        elseif (is_array($e) && isset($e['name'])) $candidates[] = $e['name'];
                    }
                }
            } catch (\Throwable $e) {
                // ignore and fall back
            }
        }

        // Fallback: lightweight TF-style keywords
        $words = array_filter(str_word_count(strtolower($text), 1), fn($w) => strlen($w) > 3);
        $freq = [];
        foreach ($words as $w) $freq[$w] = ($freq[$w] ?? 0) + 1;
        arsort($freq);
        $top = array_slice(array_keys($freq), 0, 20);

        // Merge entity candidates (preserve original casing if possible)
        $merged = array_values(array_unique(array_merge($candidates, $top)));

        // Limit to top 10
        return array_slice($merged, 0, 10);
    }
    
    private function extractEntities(string $text): array {
        // Use AI adapter if available
        if (isset($this->ai) && $this->ai !== null) {
            try {
                $ents = $this->ai->extractEntities($text);
                if (is_array($ents)) return $ents;
            } catch (\Throwable $e) {
                // fall through to heuristic
            }
        }

        // Heuristic entity extraction: proper nouns and capitalized phrases
        $entities = [];
        // Find sequences of capitalized words
        if (preg_match_all('/\b([A-Z][a-z0-9]+(?:\s+[A-Z][a-z0-9]+)*)\b/', $text, $m)) {
            foreach ($m[1] as $match) {
                $entities[] = trim($match);
            }
        }

        // Deduplicate and return
        return array_values(array_unique($entities));
    }
    
    private function extractClaims(string $text): array {
        // Try to leverage AI relationships extraction when available
        $claims = [];
        if (isset($this->ai) && $this->ai !== null) {
            try {
                $rels = $this->ai->extractRelationships($text);
                if (is_array($rels) && !empty($rels)) {
                    foreach ($rels as $r) {
                        if (is_array($r) && isset($r['subject']) && isset($r['predicate']) && isset($r['object'])) {
                            $claims[] = trim($r['subject'] . ' ' . $r['predicate'] . ' ' . $r['object']);
                        } elseif (is_string($r)) {
                            $claims[] = $r;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore and fall back
            }
        }

        if (!empty($claims)) return array_values(array_unique($claims));

        // Heuristic fallback: pick declarative sentences likely to be claims
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text));
        $verbs = [' is ', ' are ', ' was ', ' were ', ' says ', ' said ', ' claims ', ' believe ', ' believes ', ' found ', ' shows ', ' indicates ', ' reports ', ' suggests ', ' states ', ' announced ', ' confirmed ', ' implies '];
        foreach ($sentences as $s) {
            $sTrim = trim($s);
            if (strlen($sTrim) < 30) continue; // too short to be substantive
            $lower = ' ' . strtolower($sTrim) . ' ';
            foreach ($verbs as $v) {
                if (strpos($lower, $v) !== false) {
                    $claims[] = $sTrim;
                    break;
                }
            }
            if (count($claims) >= 10) break;
        }

        return array_values(array_unique($claims));
    }
}