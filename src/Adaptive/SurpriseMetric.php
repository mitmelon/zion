<?php
namespace ZionXMemory\Adaptive;

use ZionXMemory\Contracts\SurpriseMetricInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;

/**
 * SurpriseMetric - MIRAS-inspired epistemic impact scoring
 * Calculates importance/novelty without accessing model internals
 * 
 * @package ZionXMemory\Adaptive
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 2.0.0
 */

class SurpriseMetric implements SurpriseMetricInterface {
    private AIAdapterInterface $ai;
    private StorageAdapterInterface $storage;
    private array $config;
    
    public function __construct(
        AIAdapterInterface $ai,
        StorageAdapterInterface $storage,
        array $config = []
    ) {
        $this->ai = $ai;
        $this->storage = $storage;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * Calculate novelty - how different is this from existing memory?
     * Uses semantic similarity + information-theoretic measures
     */
    public function calculateNovelty(array $newContent, array $existingContext): float {
        if (empty($existingContext)) {
            return 1.0; // Completely novel if no existing context
        }
        
        $newText = $this->extractText($newContent);
        $existingTexts = array_map(fn($c) => $this->extractText($c), $existingContext);
        
        // Calculate semantic novelty using embeddings
        $semanticNovelty = $this->calculateSemanticNovelty($newText, $existingTexts);
        
        // Calculate lexical novelty (new terms/concepts)
        $lexicalNovelty = $this->calculateLexicalNovelty($newText, $existingTexts);
        
        // Calculate information gain
        $informationGain = $this->calculateInformationGain($newText, $existingTexts);
        
        // Composite score
        $weights = $this->config['novelty_weights'];
        $novelty = 
            ($weights['semantic'] * $semanticNovelty) +
            ($weights['lexical'] * $lexicalNovelty) +
            ($weights['information'] * $informationGain);
        
        return min(1.0, max(0.0, $novelty));
    }
    
    /**
     * Calculate contradiction impact
     * Higher score = more significant contradiction
     */
    public function calculateContradictionImpact(array $newClaim, array $existingBeliefs): float {
        if (empty($existingBeliefs)) {
            return 0.0;
        }
        
        $maxImpact = 0.0;
        
        foreach ($existingBeliefs as $belief) {
            // Check semantic contradiction
            $contradiction = $this->detectContradiction($newClaim, $belief);
            
            if ($contradiction['is_contradictory']) {
                // Weight by confidence of existing belief
                $existingConfidence = $belief['confidence']['mean'] ?? 0.5;
                $newConfidence = $newClaim['confidence']['mean'] ?? 0.5;
                
                // Higher impact if both have high confidence
                $impact = $contradiction['strength'] * ($existingConfidence + $newConfidence) / 2;
                
                $maxImpact = max($maxImpact, $impact);
            }
        }
        
        return min(1.0, $maxImpact);
    }
    
    /**
     * Calculate confidence shift magnitude
     * Tracks how much beliefs are changing
     */
    public function calculateConfidenceShift(array $oldConfidence, array $newConfidence): float {
        $oldMean = $oldConfidence['mean'] ?? 0.5;
        $newMean = $newConfidence['mean'] ?? 0.5;
        
        // Absolute change in mean confidence
        $meanShift = abs($newMean - $oldMean);
        
        // Change in uncertainty (range)
        $oldRange = ($oldConfidence['max'] ?? 0.7) - ($oldConfidence['min'] ?? 0.3);
        $newRange = ($newConfidence['max'] ?? 0.7) - ($newConfidence['min'] ?? 0.3);
        $rangeShift = abs($newRange - $oldRange);
        
        // Composite shift with emphasis on mean
        $shift = (0.7 * $meanShift) + (0.3 * $rangeShift);
        
        return min(1.0, $shift);
    }
    
    /**
     * Calculate evidence accumulation score
     * More evidence = more important to retain
     */
    public function calculateEvidenceAccumulation(array $evidence): float {
        $count = count($evidence);
        
        if ($count === 0) {
            return 0.0;
        }
        
        // Quality-weighted count
        $qualitySum = 0.0;
        foreach ($evidence as $item) {
            $quality = $item['quality'] ?? 0.5;
            $qualitySum += $quality;
        }
        
        // Logarithmic scaling to prevent domination by quantity
        $score = log(1 + $qualitySum) / log(1 + 100); // Normalize to 100 max
        
        return min(1.0, $score);
    }
    
    /**
     * Calculate agent disagreement signal
     * Multi-agent conflicts indicate important epistemic boundaries
     */
    public function calculateDisagreementSignal(array $agentBeliefs): float {
        if (count($agentBeliefs) < 2) {
            return 0.0;
        }
        
        // Calculate variance in beliefs
        $confidences = array_map(fn($b) => $b['confidence']['mean'] ?? 0.5, $agentBeliefs);
        $variance = $this->calculateVariance($confidences);
        
        // Calculate semantic distance between beliefs
        $texts = array_map(fn($b) => $b['claim'] ?? '', $agentBeliefs);
        $semanticDispersion = $this->calculateSemanticDispersion($texts);
        
        // Higher disagreement = higher importance
        $disagreement = (0.5 * $variance) + (0.5 * $semanticDispersion);
        
        return min(1.0, $disagreement * 2); // Amplify to [0,1] range
    }
    
    /**
     * Compute composite surprise score
     * Combines multiple signals with configurable weights
     */
    public function computeCompositeSurprise(array $signals, array $weights): array {
        $normalizedWeights = $this->normalizeWeights($weights);
        
        $compositeScore = 0.0;
        $components = [];
        
        foreach ($signals as $signalType => $value) {
            $weight = $normalizedWeights[$signalType] ?? 0.0;
            $contribution = $weight * $value;
            $compositeScore += $contribution;
            
            $components[$signalType] = [
                'value' => $value,
                'weight' => $weight,
                'contribution' => $contribution
            ];
        }
        
        // Add momentum term for temporal dynamics
        $momentum = $this->calculateMomentum($signals);
        $compositeScore = (0.9 * $compositeScore) + (0.1 * $momentum);
        
        return [
            'composite_score' => min(1.0, $compositeScore),
            'components' => $components,
            'momentum' => $momentum,
            'computed_at' => time()
        ];
    }
    
    /**
     * Calculate semantic novelty using embeddings
     */
    private function calculateSemanticNovelty(string $newText, array $existingTexts): float {
        // Use AI to compute semantic similarity
        $prompt = "Rate semantic novelty of the new text compared to existing context. Return JSON: {\"novelty\": 0.0-1.0}";
        
        try {
            $result = $this->ai->scoreEpistemicConfidence($prompt, [
                'new_text' => $newText,
                'existing_context' => implode("\n", array_slice($existingTexts, 0, 10))
            ]);
            
            return $result['novelty'] ?? 0.5;
        } catch (\Exception $e) {
            // Fallback to simple heuristic
            return $this->calculateLexicalNovelty($newText, $existingTexts);
        }
    }
    
    /**
     * Calculate lexical novelty (new terms)
     */
    private function calculateLexicalNovelty(string $newText, array $existingTexts): float {
        $newWords = $this->extractWords($newText);
        $existingWords = [];
        
        foreach ($existingTexts as $text) {
            $existingWords = array_merge($existingWords, $this->extractWords($text));
        }
        
        $existingWords = array_unique($existingWords);
        
        if (empty($newWords)) {
            return 0.0;
        }
        
        $novelWords = array_diff($newWords, $existingWords);
        $noveltyRatio = count($novelWords) / count($newWords);
        
        return min(1.0, $noveltyRatio * 2); // Amplify
    }
    
    /**
     * Calculate information gain (entropy-based)
     */
    private function calculateInformationGain(string $newText, array $existingTexts): float {
        // Simplified information-theoretic measure
        $newEntropy = $this->calculateEntropy($newText);
        
        $avgExistingEntropy = 0.0;
        if (!empty($existingTexts)) {
            foreach ($existingTexts as $text) {
                $avgExistingEntropy += $this->calculateEntropy($text);
            }
            $avgExistingEntropy /= count($existingTexts);
        }
        
        // Normalized information gain
        $gain = ($newEntropy - $avgExistingEntropy + 5) / 10; // Normalize around 5 bits
        
        return min(1.0, max(0.0, $gain));
    }
    
    /**
     * Detect contradiction between claims
     */
    private function detectContradiction(array $newClaim, array $existingBelief): array {
        $newText = $newClaim['text'] ?? $newClaim['claim'] ?? '';
        $existingText = $existingBelief['text'] ?? $existingBelief['claim'] ?? '';
        
        // Simple negation detection
        $negations = ['not', 'no', 'never', 'false', 'incorrect', 'wrong'];
        
        $newLower = strtolower($newText);
        $existingLower = strtolower($existingText);
        
        $newHasNeg = false;
        $existingHasNeg = false;
        
        foreach ($negations as $neg) {
            if (str_contains($newLower, $neg)) $newHasNeg = true;
            if (str_contains($existingLower, $neg)) $existingHasNeg = true;
        }
        
        // XOR: one negated, other not
        $isContradictory = $newHasNeg !== $existingHasNeg;
        
        // Calculate strength based on semantic overlap
        $overlap = $this->calculateSemanticOverlap($newText, $existingText);
        $strength = $overlap * ($isContradictory ? 1.0 : 0.0);
        
        return [
            'is_contradictory' => $isContradictory,
            'strength' => $strength
        ];
    }
    
    /**
     * Calculate variance
     */
    private function calculateVariance(array $values): float {
        if (empty($values)) return 0.0;
        
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        
        return sqrt(array_sum($squaredDiffs) / count($values));
    }
    
    /**
     * Calculate semantic dispersion
     */
    private function calculateSemanticDispersion(array $texts): float {
        if (count($texts) < 2) return 0.0;
        
        // Pairwise distance average
        $distances = [];
        for ($i = 0; $i < count($texts); $i++) {
            for ($j = $i + 1; $j < count($texts); $j++) {
                $distances[] = $this->calculateSemanticDistance($texts[$i], $texts[$j]);
            }
        }
        
        return !empty($distances) ? array_sum($distances) / count($distances) : 0.0;
    }
    
    private function calculateSemanticDistance(string $text1, string $text2): float {
        // Simplified: Jaccard distance on words
        $words1 = array_unique($this->extractWords($text1));
        $words2 = array_unique($this->extractWords($text2));
        
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));
        
        return $union > 0 ? 1 - ($intersection / $union) : 1.0;
    }
    
    private function calculateSemanticOverlap(string $text1, string $text2): float {
        return 1.0 - $this->calculateSemanticDistance($text1, $text2);
    }
    
    /**
     * Calculate momentum (temporal derivative of surprise)
     */
    private function calculateMomentum(array $signals): float {
        // If we have temporal signals, calculate rate of change
        // For now, return neutral
        return 0.5;
    }
    
    private function normalizeWeights(array $weights): array {
        $sum = array_sum($weights);
        if ($sum === 0.0) return $weights;
        
        return array_map(fn($w) => $w / $sum, $weights);
    }
    
    private function extractText($content): string {
        if (is_string($content)) return $content;
        if (isset($content['content'])) return $content['content'];
        if (isset($content['text'])) return $content['text'];
        return json_encode($content);
    }
    
    private function extractWords(string $text): array {
        $words = str_word_count(strtolower($text), 1);
        return array_filter($words, fn($w) => strlen($w) > 2); // Remove short words
    }
    
    private function calculateEntropy(string $text): float {
        $length = strlen($text);
        if ($length === 0) return 0;
        
        $frequencies = [];
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            $frequencies[$char] = ($frequencies[$char] ?? 0) + 1;
        }
        
        $entropy = 0;
        foreach ($frequencies as $count) {
            $probability = $count / $length;
            $entropy -= $probability * log($probability, 2);
        }
        
        return $entropy;
    }
    
    private function getDefaultConfig(): array {
        return [
            'novelty_weights' => [
                'semantic' => 0.5,
                'lexical' => 0.3,
                'information' => 0.2
            ]
        ];
    }
}