<?php
namespace ZionXMemory\Adaptive;

use ZionXMemory\Contracts\AdaptiveMemoryInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;
use ZionXMemory\Contracts\JobDispatcherInterface;

/**
 * AdaptiveMemory - Main adaptive memory module
 * MIRAS-inspired importance weighting and retention WITHOUT behavior control
 * 
 * @package ZionXMemory\Adaptive
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

class AdaptiveMemory implements AdaptiveMemoryInterface {
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    private AuditInterface $audit;
    private ?JobDispatcherInterface $dispatcher;
    private SurpriseMetric $surpriseMetric;
    private RetentionGate $retentionGate;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AIAdapterInterface $ai,
        AuditInterface $audit
        , JobDispatcherInterface $dispatcher = null
    ) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->audit = $audit;
        $this->dispatcher = $dispatcher;
        $this->surpriseMetric = new SurpriseMetric($ai, $storage);
        $this->retentionGate = new RetentionGate($storage, $audit);
    }
    
    /**
     * Store memory with adaptive signals
     * Accepts surprise signal from agent but doesn't enforce behavior
     */
    public function storeAdaptiveMemory(
        string $tenantId,
        string $agentId,
        array $content,
        array $surpriseSignal,
        array $metadata
    ): string {
        $memoryId = $this->generateMemoryId();
        $timestamp = time();
        
        // Process surprise signal
        $processedSurprise = $this->processSurpriseSignal($surpriseSignal);
        
        // Compute composite surprise if not provided
        if (!isset($processedSurprise['composite_score'])) {
            $existingContext = $this->getRelevantContext($tenantId, $agentId);
            $computedSurprise = $this->computeSurprise($existingContext, $content);
            $processedSurprise = array_merge($processedSurprise, $computedSurprise);
        }
        
        // Build adaptive memory unit
        $memoryUnit = [
            'id' => $memoryId,
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'content' => $content,
            'timestamp' => $timestamp,
            'surprise_signal' => $surpriseSignal,
            'surprise_score' => $processedSurprise['composite_score'],
            'surprise_components' => $processedSurprise['components'] ?? [],
            'importance' => $this->calculateInitialImportance($processedSurprise),
            'layer' => $this->determineInitialLayer($processedSurprise),
            'metadata' => $metadata,
            'access_count' => 0,
            'last_access' => $timestamp,
            'retention_status' => 'active',
            'evidence' => $metadata['evidence'] ?? [],
            'contradiction_count' => 0,
            'confidence' => $content['confidence'] ?? ['min' => 0.3, 'max' => 0.7, 'mean' => 0.5]
        ];
        
        // Store
        $key = $this->buildMemoryKey($tenantId, $memoryId);
        $this->storage->write($key, $memoryUnit, [
            'tenant' => $tenantId,
            'type' => 'adaptive_memory',
            'layer' => $memoryUnit['layer']
        ]);
        
        // Update indices
        $this->updateSurpriseIndex($tenantId, $memoryId, $processedSurprise['composite_score']);
        $this->updateLayerIndex($tenantId, $memoryId, $memoryUnit['layer']);
        
        // Audit
        $this->audit->log($tenantId, 'adaptive_memory_stored', [
            'memory_id' => $memoryId,
            'agent_id' => $agentId,
            'surprise_score' => $processedSurprise['composite_score'],
            'layer' => $memoryUnit['layer']
        ], ['timestamp' => $timestamp]);
        
        // Trigger retention evaluation (async in production)
        $this->scheduleRetentionEvaluation($tenantId);
        
        return $memoryId;
    }
    
    /**
     * Compute surprise for new memory
     * External surprise calculation without model internals
     */
    public function computeSurprise(array $existingMemories, array $newMemory): array {
        $signals = [];
        
        // Novelty
        $signals['novelty'] = $this->surpriseMetric->calculateNovelty(
            $newMemory,
            $existingMemories
        );
        
        // Contradiction impact (if claims present)
        if (isset($newMemory['claims'])) {
            $existingBeliefs = $this->extractBeliefs($existingMemories);
            $maxContradiction = 0.0;
            
            foreach ($newMemory['claims'] as $claim) {
                $contradiction = $this->surpriseMetric->calculateContradictionImpact(
                    $claim,
                    $existingBeliefs
                );
                $maxContradiction = max($maxContradiction, $contradiction);
            }
            
            $signals['contradiction'] = $maxContradiction;
        } else {
            $signals['contradiction'] = 0.0;
        }
        
        // Evidence accumulation
        $signals['evidence'] = $this->surpriseMetric->calculateEvidenceAccumulation(
            $newMemory['evidence'] ?? []
        );
        
        // Confidence shift (if updating existing)
        if (isset($newMemory['previous_confidence'])) {
            $signals['confidence_shift'] = $this->surpriseMetric->calculateConfidenceShift(
                $newMemory['previous_confidence'],
                $newMemory['confidence'] ?? ['min' => 0.3, 'max' => 0.7, 'mean' => 0.5]
            );
        } else {
            $signals['confidence_shift'] = 0.0;
        }
        
        // Default weights
        $weights = [
            'novelty' => 0.35,
            'contradiction' => 0.30,
            'evidence' => 0.20,
            'confidence_shift' => 0.15
        ];
        
        return $this->surpriseMetric->computeCompositeSurprise($signals, $weights);
    }
    
    /**
     * Promote memory to active layer
     * Provides recommendation, doesn't enforce
     */
    public function promoteToActiveMemory(string $tenantId, string $memoryUnitId, string $reason): bool {
        $key = $this->buildMemoryKey($tenantId, $memoryUnitId);
        $memory = $this->storage->read($key);
        
        if (!$memory) {
            return false;
        }
        
        $oldLayer = $memory['layer'];
        $newLayer = 'hot';
        
        // Update memory
        $memory['layer'] = $newLayer;
        $memory['promoted_at'] = time();
        $memory['promotion_reason'] = $reason;
        $memory['importance'] = min(1.0, $memory['importance'] * 1.2); // Boost importance
        
        $this->storage->write($key, $memory, [
            'tenant' => $tenantId,
            'type' => 'adaptive_memory',
            'layer' => $newLayer
        ]);
        
        // Update indices
        $this->updateLayerIndex($tenantId, $memoryUnitId, $newLayer);
        
        $this->audit->log($tenantId, 'memory_promoted', [
            'memory_id' => $memoryUnitId,
            'from_layer' => $oldLayer,
            'to_layer' => $newLayer,
            'reason' => $reason
        ], ['timestamp' => time()]);
        
        return true;
    }
    
    /**
     * Demote memory to compressed layer
     */
    public function demoteToCompressedMemory(string $tenantId, string $memoryUnitId, string $reason): bool {
        $key = $this->buildMemoryKey($tenantId, $memoryUnitId);
        $memory = $this->storage->read($key);
        
        if (!$memory) {
            return false;
        }
        
        $oldLayer = $memory['layer'];
        $newLayer = 'cold';
        
        // Update memory
        $memory['layer'] = $newLayer;
        $memory['demoted_at'] = time();
        $memory['demotion_reason'] = $reason;
        $memory['importance'] = $memory['importance'] * 0.8; // Reduce importance
        
        $this->storage->write($key, $memory, [
            'tenant' => $tenantId,
            'type' => 'adaptive_memory',
            'layer' => $newLayer
        ]);
        
        // Update indices
        $this->updateLayerIndex($tenantId, $memoryUnitId, $newLayer);
        
        $this->audit->log($tenantId, 'memory_demoted', [
            'memory_id' => $memoryUnitId,
            'from_layer' => $oldLayer,
            'to_layer' => $newLayer,
            'reason' => $reason
        ], ['timestamp' => time()]);
        
        return true;
    }
    
    /**
     * Query memories by surprise threshold
     */
    public function queryMemoryBySurprise(string $tenantId, array $thresholds, array $filters): array {
        $pattern = $this->buildMemoryKey($tenantId, '*');
        $allMemories = $this->storage->query(['pattern' => $pattern]);
        
        $minSurprise = $thresholds['min'] ?? 0.0;
        $maxSurprise = $thresholds['max'] ?? 1.0;
        
        $filtered = array_filter($allMemories, function($memory) use ($minSurprise, $maxSurprise, $filters) {
            // Surprise threshold
            $surprise = $memory['surprise_score'] ?? 0.5;
            if ($surprise < $minSurprise || $surprise > $maxSurprise) {
                return false;
            }
            
            // Additional filters
            if (isset($filters['agent_id']) && $memory['agent_id'] !== $filters['agent_id']) {
                return false;
            }
            
            if (isset($filters['layer']) && $memory['layer'] !== $filters['layer']) {
                return false;
            }
            
            if (isset($filters['min_importance']) && ($memory['importance'] ?? 0) < $filters['min_importance']) {
                return false;
            }
            
            return true;
        });
        
        // Sort by surprise score (descending)
        usort($filtered, fn($a, $b) => ($b['surprise_score'] ?? 0) <=> ($a['surprise_score'] ?? 0));
        
        return array_values($filtered);
    }
    
    /**
     * Get retention policy status
     */
    public function getRetentionPolicyStatus(string $tenantId): array {
        $policy = $this->getRetentionPolicy($tenantId);
        
        // Get memory distribution by layer
        $distribution = $this->getMemoryDistribution($tenantId);
        
        // Get forgetting candidates
        $candidates = $this->retentionGate->getForgettingCandidates($tenantId, []);
        
        return [
            'policy' => $policy,
            'distribution' => $distribution,
            'forgetting_candidates' => count($candidates),
            'total_memories' => array_sum($distribution),
            'evaluated_at' => time()
        ];
    }
    
    /**
     * Update retention policy
     */
    public function updateRetentionPolicy(string $tenantId, array $policy): bool {
        // Validate policy
        $requiredKeys = ['retention_weights', 'promotion_threshold', 'compression_threshold'];
        foreach ($requiredKeys as $key) {
            if (!isset($policy[$key])) {
                return false;
            }
        }
        
        $key = "retention_policy:{$tenantId}";
        $this->storage->write($key, $policy, ['tenant' => $tenantId]);
        
        $this->audit->log($tenantId, 'retention_policy_updated', [
            'policy' => $policy
        ], ['timestamp' => time()]);
        
        return true;
    }
    
    /**
     * Process surprise signal from agent
     */
    private function processSurpriseSignal(array $signal): array {
        return [
            'composite_score' => $signal['magnitude'] ?? $signal['score'] ?? 0.5,
            'momentum' => $signal['momentum'] ?? 0.0,
            'components' => $signal['components'] ?? [],
            'timestamp' => $signal['timestamp'] ?? time()
        ];
    }
    
    /**
     * Calculate initial importance from surprise
     */
    private function calculateInitialImportance(array $surprise): float {
        $score = $surprise['composite_score'] ?? 0.5;
        
        // High surprise = high initial importance
        return $score;
    }
    
    /**
     * Determine initial storage layer based on surprise
     */
    private function determineInitialLayer(array $surprise): string {
        $score = $surprise['composite_score'] ?? 0.5;
        
        if ($score >= 0.7) {
            return 'hot';
        } elseif ($score >= 0.4) {
            return 'warm';
        }
        
        return 'cold';
    }
    
    /**
     * Get relevant context for surprise calculation
     */
    private function getRelevantContext(string $tenantId, string $agentId): array {
        $pattern = $this->buildMemoryKey($tenantId, '*');
        $allMemories = $this->storage->query(['pattern' => $pattern]);
        
        // Filter by agent and recency
        $filtered = array_filter($allMemories, function($m) use ($agentId) {
            return $m['agent_id'] === $agentId;
        });
        
        // Return most recent 50
        usort($filtered, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        
        return array_slice($filtered, 0, 50);
    }
    
    /**
     * Extract beliefs from memories
     */
    private function extractBeliefs(array $memories): array {
        $beliefs = [];
        
        foreach ($memories as $memory) {
            if (isset($memory['content']['claims'])) {
                $beliefs = array_merge($beliefs, $memory['content']['claims']);
            }
        }
        
        return $beliefs;
    }
    
    /**
     * Update surprise index for efficient querying
     */
    private function updateSurpriseIndex(string $tenantId, string $memoryId, float $score): void {
        $bucket = $this->getSurpriseBucket($score);
        $key = "surprise_index:{$tenantId}:{$bucket}";
        
        $index = $this->storage->read($key) ?? [];
        $index[] = $memoryId;
        
        $this->storage->write($key, $index, ['tenant' => $tenantId]);
    }
    
    /**
     * Update layer index
     */
    private function updateLayerIndex(string $tenantId, string $memoryId, string $layer): void {
        $key = "layer_index:{$tenantId}:{$layer}";
        
        $index = $this->storage->read($key) ?? [];
        if (!in_array($memoryId, $index)) {
            $index[] = $memoryId;
        }
        
        $this->storage->write($key, $index, ['tenant' => $tenantId]);
    }
    
    /**
     * Get memory distribution by layer
     */
    private function getMemoryDistribution(string $tenantId): array {
        $layers = ['hot', 'warm', 'cold', 'frozen'];
        $distribution = [];
        
        foreach ($layers as $layer) {
            $key = "layer_index:{$tenantId}:{$layer}";
            $index = $this->storage->read($key) ?? [];
            $distribution[$layer] = count($index);
        }
        
        return $distribution;
    }
    
    /**
     * Schedule retention evaluation (would be async in production)
     */
    private function scheduleRetentionEvaluation(string $tenantId): void {
        // In production, prefer dispatching a background job via JobDispatcher
        if ($this->dispatcher !== null) {
            try {
                $jobId = $this->dispatcher->dispatchRetentionEvaluation($tenantId);
            } catch (\Throwable $e) {
                $jobId = null;
            }

            if ($jobId !== null) {
                $key = "retention_eval_pending:{$tenantId}";
                $payload = ['job_id' => $jobId, 'status' => 'queued', 'queued_at' => time()];
                $this->storage->write($key, $payload, ['tenant' => $tenantId]);
                return;
            }
            // If dispatch failed, fall through to legacy pending marker
        }

        // Fallback: mark as pending in storage (synchronous compatibility)
        $key = "retention_eval_pending:{$tenantId}";
        $this->storage->write($key, ['queued_at' => time()], ['tenant' => $tenantId]);
    }
    
    private function getSurpriseBucket(float $score): string {
        if ($score >= 0.8) return 'very_high';
        if ($score >= 0.6) return 'high';
        if ($score >= 0.4) return 'medium';
        if ($score >= 0.2) return 'low';
        return 'very_low';
    }
    
    private function getRetentionPolicy(string $tenantId): array {
        $key = "retention_policy:{$tenantId}";
        return $this->storage->read($key) ?? $this->retentionGate->getDefaultPolicy();
    }
    
    private function buildMemoryKey(string $tenantId, string $memoryId): string {
        return "adaptive_memory:{$tenantId}:{$memoryId}";
    }
    
    private function generateMemoryId(): string {
        return 'amem_' . bin2hex(random_bytes(16));
    }
}