<?php
namespace ZionXMemory\Adaptive;

use ZionXMemory\Contracts\RetentionGateInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;

/**
 * RetentionGate - MIRAS-inspired adaptive forgetting
 * Implements controlled memory decay and retention policies
 * 
 * @package ZionXMemory\Adaptive
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

class RetentionGate implements RetentionGateInterface {
    private StorageAdapterInterface $storage;
    private AuditInterface $audit;
    private array $defaultPolicy;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AuditInterface $audit
    ) {
        $this->storage = $storage;
        $this->audit = $audit;
        $this->defaultPolicy = $this->getDefaultPolicy();
    }
    
    /**
     * Evaluate memory unit for retention
     * Returns retention decision without enforcing it
     */
    public function evaluateRetention(string $tenantId, string $memoryUnitId): array {
        $key = $this->buildMemoryKey($tenantId, $memoryUnitId);
        $memoryUnit = $this->storage->read($key);
        
        if (!$memoryUnit) {
            return ['decision' => 'not_found', 'score' => 0.0];
        }
        
        $policy = $this->getPolicy($tenantId);
        
        // Calculate retention score based on multiple factors
        $scores = [
            'surprise' => $this->evaluateSurpriseRetention($memoryUnit),
            'confidence' => $this->evaluateConfidenceRetention($memoryUnit, $policy),
            'contradiction' => $this->evaluateContradictionRetention($memoryUnit),
            'temporal' => $this->evaluateTemporalRetention($memoryUnit, $policy),
            'usage' => $this->evaluateUsageRetention($memoryUnit),
            'evidence' => $this->evaluateEvidenceRetention($memoryUnit)
        ];
        
        // Weighted composite
        $weights = $policy['retention_weights'];
        $compositeScore = 0.0;
        
        foreach ($scores as $factor => $score) {
            $weight = $weights[$factor] ?? 0.1;
            $compositeScore += $weight * $score;
        }
        
        // Make decision
        $decision = $this->makeRetentionDecision($compositeScore, $policy);
        
        return [
            'decision' => $decision,
            'composite_score' => $compositeScore,
            'factor_scores' => $scores,
            'policy_applied' => $policy['name'] ?? 'default',
            'evaluated_at' => time()
        ];
    }
    
    /**
     * Apply decay to memory importance over time
     * MIRAS-style adaptive weight decay
     */
    public function applyDecay(string $tenantId, array $memoryUnits, float $decayRate): array {
        $decayed = [];
        $now = time();
        
        foreach ($memoryUnits as $unit) {
            $age = $now - ($unit['timestamp'] ?? $now);
            $ageInDays = $age / 86400;
            
            // Exponential decay with surprise-based resistance
            $surpriseScore = $unit['surprise_score'] ?? 0.5;
            $resistanceFactor = 1.0 + $surpriseScore; // High surprise = slower decay
            
            $decayFactor = exp(-$decayRate * $ageInDays / $resistanceFactor);
            
            $originalImportance = $unit['importance'] ?? 1.0;
            $newImportance = $originalImportance * $decayFactor;
            
            $unit['importance'] = $newImportance;
            $unit['last_decay'] = $now;
            $unit['decay_applied'] = $decayFactor;
            
            $decayed[] = $unit;
        }
        
        $this->audit->log($tenantId, 'retention_decay_applied', [
            'units_processed' => count($memoryUnits),
            'decay_rate' => $decayRate
        ], ['timestamp' => $now]);
        
        return $decayed;
    }
    
    /**
     * Check if memory should be compressed
     */
    public function shouldCompress(array $memoryUnit, array $policy): bool {
        $importance = $memoryUnit['importance'] ?? 1.0;
        $age = time() - ($memoryUnit['timestamp'] ?? time());
        $ageInDays = $age / 86400;
        
        // Compress if:
        // 1. Low importance AND old enough
        // 2. Exceeds age threshold regardless of importance (but preserve high-surprise)
        
        $compressionThreshold = $policy['compression_threshold'] ?? 0.3;
        $ageThreshold = $policy['compression_age_days'] ?? 30;
        $surpriseScore = $memoryUnit['surprise_score'] ?? 0.5;
        
        $lowImportance = $importance < $compressionThreshold;
        $oldEnough = $ageInDays > $ageThreshold;
        $notHighSurprise = $surpriseScore < 0.8;
        
        return ($lowImportance && $oldEnough) || ($oldEnough && $notHighSurprise);
    }
    
    /**
     * Check if memory should be promoted to active layer
     */
    public function shouldPromote(array $memoryUnit, array $policy): bool {
        $importance = $memoryUnit['importance'] ?? 0.5;
        $surpriseScore = $memoryUnit['surprise_score'] ?? 0.5;
        $recentAccess = $this->hasRecentAccess($memoryUnit);
        
        $promotionThreshold = $policy['promotion_threshold'] ?? 0.7;
        
        // Promote if high importance OR high surprise OR recently accessed
        return ($importance > $promotionThreshold) ||
               ($surpriseScore > $promotionThreshold) ||
               $recentAccess;
    }
    
    /**
     * Get candidates for forgetting (compression/archival)
     */
    public function getForgettingCandidates(string $tenantId, array $criteria): array {
        $pattern = $this->buildMemoryKey($tenantId, '*');
        $allMemories = $this->storage->query(['pattern' => $pattern]);
        
        $policy = $this->getPolicy($tenantId);
        $candidates = [];
        
        foreach ($allMemories as $memory) {
            $shouldForget = $this->shouldCompress($memory, $policy);
            
            // Apply additional criteria
            if (isset($criteria['max_age_days'])) {
                $age = (time() - $memory['timestamp']) / 86400;
                if ($age < $criteria['max_age_days']) {
                    $shouldForget = false;
                }
            }
            
            if (isset($criteria['min_importance'])) {
                if ($memory['importance'] > $criteria['min_importance']) {
                    $shouldForget = false;
                }
            }
            
            if ($shouldForget) {
                $candidates[] = [
                    'memory_id' => $memory['id'],
                    'importance' => $memory['importance'] ?? 0.5,
                    'age_days' => (time() - $memory['timestamp']) / 86400,
                    'surprise_score' => $memory['surprise_score'] ?? 0.5,
                    'reason' => 'low_importance_aged'
                ];
            }
        }
        
        // Sort by importance (lowest first)
        usort($candidates, fn($a, $b) => $a['importance'] <=> $b['importance']);
        
        return $candidates;
    }
    
    /**
     * Evaluate surprise-based retention
     */
    private function evaluateSurpriseRetention(array $memoryUnit): float {
        $surpriseScore = $memoryUnit['surprise_score'] ?? 0.5;
        
        // High surprise = high retention
        return $surpriseScore;
    }
    
    /**
     * Evaluate confidence-based retention
     */
    private function evaluateConfidenceRetention(array $memoryUnit, array $policy): float {
        $confidence = $memoryUnit['confidence']['mean'] ?? 0.5;
        $threshold = $policy['confidence_retention_threshold'] ?? 0.6;
        
        // High confidence = high retention
        // But also retain low confidence (uncertainty is valuable)
        if ($confidence > $threshold) {
            return $confidence;
        } elseif ($confidence < (1.0 - $threshold)) {
            return 1.0 - $confidence; // Retain uncertainty
        }
        
        return 0.5;
    }
    
    /**
     * Evaluate contradiction-based retention
     */
    private function evaluateContradictionRetention(array $memoryUnit): float {
        $contradictionCount = $memoryUnit['contradiction_count'] ?? 0;
        
        // More contradictions = more important to retain
        return min(1.0, $contradictionCount * 0.2);
    }
    
    /**
     * Evaluate temporal/recency retention
     */
    private function evaluateTemporalRetention(array $memoryUnit, array $policy): float {
        $age = time() - ($memoryUnit['timestamp'] ?? time());
        $ageInDays = $age / 86400;
        
        $halfLife = $policy['temporal_half_life_days'] ?? 7;
        
        // Exponential decay in temporal salience
        return exp(-0.693 * $ageInDays / $halfLife); // 0.693 ≈ ln(2)
    }
    
    /**
     * Evaluate usage-based retention
     */
    private function evaluateUsageRetention(array $memoryUnit): float {
        $accessCount = $memoryUnit['access_count'] ?? 0;
        $lastAccess = $memoryUnit['last_access'] ?? 0;
        
        $recency = time() - $lastAccess;
        $recencyScore = $recency > 0 ? 1.0 / (1 + log(1 + $recency / 86400)) : 1.0;
        
        $frequencyScore = min(1.0, log(1 + $accessCount) / log(100));
        
        return (0.6 * $frequencyScore) + (0.4 * $recencyScore);
    }
    
    /**
     * Evaluate evidence-based retention
     */
    private function evaluateEvidenceRetention(array $memoryUnit): float {
        $evidenceCount = count($memoryUnit['evidence'] ?? []);
        
        // More evidence = higher retention
        return min(1.0, log(1 + $evidenceCount) / log(20));
    }
    
    /**
     * Make retention decision based on score and policy
     */
    private function makeRetentionDecision(float $score, array $policy): string {
        $promoteThreshold = $policy['promotion_threshold'] ?? 0.7;
        $compressThreshold = $policy['compression_threshold'] ?? 0.3;
        
        if ($score >= $promoteThreshold) {
            return 'promote_to_active';
        } elseif ($score < $compressThreshold) {
            return 'compress_to_cold';
        }
        
        return 'maintain_current_layer';
    }
    
    /**
     * Check if memory was accessed recently
     */
    private function hasRecentAccess(array $memoryUnit): bool {
        $lastAccess = $memoryUnit['last_access'] ?? 0;
        $recencyWindow = 86400; // 24 hours
        
        return (time() - $lastAccess) < $recencyWindow;
    }
    
    /**
     * Get retention policy for tenant
     */
    private function getPolicy(string $tenantId): array {
        $key = "retention_policy:{$tenantId}";
        $policy = $this->storage->read($key);
        
        return $policy ?? $this->defaultPolicy;
    }
    
    /**
     * Get default retention policy
     */
    private function getDefaultPolicy(): array {
        return [
            'name' => 'default',
            'retention_weights' => [
                'surprise' => 0.25,
                'confidence' => 0.15,
                'contradiction' => 0.20,
                'temporal' => 0.15,
                'usage' => 0.15,
                'evidence' => 0.10
            ],
            'promotion_threshold' => 0.7,
            'compression_threshold' => 0.3,
            'compression_age_days' => 30,
            'confidence_retention_threshold' => 0.6,
            'temporal_half_life_days' => 7,
            'decay_rate' => 0.1 // Per day
        ];
    }
    
    private function buildMemoryKey(string $tenantId, string $memoryId): string {
        return "adaptive_memory:{$tenantId}:{$memoryId}";
    }
}