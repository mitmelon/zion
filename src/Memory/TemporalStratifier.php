<?php
namespace ZionXMemory\Memory;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\JobDispatcherInterface;

/**
 * TemporalStratifier
 * Manages hierarchical temporal memory stratification
 * Hot -> Warm -> Cold -> Frozen layers with semantic preservation
 * Implements layer-specific summarization triggers
 * Builds context snapshots with token budget allocation
 * 
 * @package ZionXMemory\Memory
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class TemporalStratifier {
    private StorageAdapterInterface $storage;
    private ?JobDispatcherInterface $dispatcher = null;
    
    // Time windows (in seconds)
    private const HOT_WINDOW = 86400;      // 24 hours
    private const WARM_WINDOW = 604800;    // 7 days
    private const COLD_WINDOW = 2592000;   // 30 days
    // Everything older is FROZEN
    
    public function __construct(StorageAdapterInterface $storage, ?JobDispatcherInterface $dispatcher = null) {
        $this->storage = $storage;
        $this->dispatcher = $dispatcher;
    }
    
    /**
     * Check if summarization is needed for a tenant/agent
     */
    public function checkSummarizationNeeded(string $tenantId, string $agentId): void {
        $now = time();
        
        // Check each layer
        $this->checkLayerSummarization($tenantId, $agentId, 'hot', $now);
        $this->checkLayerSummarization($tenantId, $agentId, 'warm', $now);
        $this->checkLayerSummarization($tenantId, $agentId, 'cold', $now);
    }
    
    /**
     * Build context snapshot from stratified memory
     */
    public function buildContext(string $tenantId, array $memories, int $maxTokens): array {
        $now = time();
        $context = [
            'hot' => [],
            'warm' => [],
            'cold' => [],
            'frozen' => [],
            'total_tokens' => 0
        ];
        
        // Classify memories by temporal layer
        foreach ($memories as $memory) {
            $age = $now - $memory['timestamp'];
            $layer = $this->classifyLayer($age);
            $context[$layer][] = $memory;
        }
        
        // Build context with token budget
        $result = $this->allocateTokenBudget($tenantId, $context, $maxTokens);
        
        return $result;
    }
    
    /**
     * Classify memory layer by age
     */
    private function classifyLayer(int $age): string {
        if ($age <= self::HOT_WINDOW) return 'hot';
        if ($age <= self::WARM_WINDOW) return 'warm';
        if ($age <= self::COLD_WINDOW) return 'cold';
        return 'frozen';
    }
    
    /**
     * Check if layer needs summarization
     */
    private function checkLayerSummarization(string $tenantId, string $agentId, string $layer, int $now): void {
        $key = $this->buildLayerKey($tenantId, $agentId, $layer);
        $metadata = $this->storage->getMetadata($key);
        
        if (!$metadata) return;
        
        $lastSummary = $metadata['last_summary'] ?? 0;
        $memoryCount = $metadata['memory_count'] ?? 0;
        
        // Trigger summarization based on layer-specific thresholds
        $threshold = $this->getSummarizationThreshold($layer);
        
        if ($memoryCount >= $threshold || ($now - $lastSummary) > $this->getSummarizationInterval($layer)) {
            $this->triggerSummarization($tenantId, $agentId, $layer);
        }
    }
    
    /**
     * Get summarization threshold by layer
     */
    private function getSummarizationThreshold(string $layer): int {
        return match($layer) {
            'hot' => 50,
            'warm' => 100,
            'cold' => 200,
            default => 50
        };
    }
    
    /**
     * Get summarization interval by layer
     */
    private function getSummarizationInterval(string $layer): int {
        return match($layer) {
            'hot' => 3600,      // 1 hour
            'warm' => 86400,    // 1 day
            'cold' => 604800,   // 1 week
            default => 3600
        };
    }
    
    /**
     * Trigger summarization for a layer
     */
    private function triggerSummarization(string $tenantId, string $agentId, string $layer): void {
        // Try to dispatch an async job via dispatcher if available. If dispatch
        // fails or none provided, fallback to the legacy pending marker.
        $key = $this->buildLayerKey($tenantId, $agentId, $layer);

        if ($this->dispatcher) {
            $jobId = $this->dispatcher->dispatchSummarization($tenantId, $agentId, $layer);

            $status = $jobId ? 'queued' : 'pending';

            $this->storage->write($key . ':pending', [
                'status' => $status,
                'queued_at' => time(),
                'job_id' => $jobId
            ], ['tenant' => $tenantId]);

            return;
        }

        // Fallback: legacy behavior (synchronous marker)
        $this->storage->write($key . ':pending', [
            'status' => 'pending',
            'queued_at' => time()
        ], ['tenant' => $tenantId]);
    }
    
    /**
     * Allocate token budget across layers
     * Hot gets most tokens, frozen gets least
     */
    private function allocateTokenBudget(string $tenantId, array $context, int $maxTokens): array {
        $allocation = [
            'hot' => 0.50,    // 50% to recent
            'warm' => 0.30,   // 30% to warm
            'cold' => 0.15,   // 15% to cold
            'frozen' => 0.05  // 5% to frozen
        ];
        
        $result = [
            'layers' => [],
            'total_tokens_used' => 0
        ];
        
        foreach (['hot', 'warm', 'cold', 'frozen'] as $layer) {
            $layerBudget = (int) ($maxTokens * $allocation[$layer]);
            $layerData = $this->buildLayerContext($tenantId, $context[$layer], $layerBudget, $layer);
            
            $result['layers'][$layer] = $layerData;
            $result['total_tokens_used'] += $layerData['tokens_used'];
        }
        
        return $result;
    }
    
    /**
     * Build context for a specific layer
     */
    private function buildLayerContext(string $tenantId, array $memories, int $tokenBudget, string $layer): array {
        // For hot layer, include full memories
        if ($layer === 'hot') {
            return [
                'type' => 'full',
                'memories' => $memories,
                'tokens_used' => $this->estimateTotalTokens($memories)
            ];
        }
        
        // For other layers, use summaries
        $summaryKey = $this->buildSummaryKey($tenantId, $layer);
        $summary = $this->storage->read($summaryKey);
        
        if ($summary) {
            return [
                'type' => 'summary',
                'summary' => $summary,
                'source_count' => count($memories),
                'tokens_used' => $this->estimateTokens($summary['summary'] ?? '')
            ];
        }
        
        // Fallback to sample
        return [
            'type' => 'sample',
            'memories' => array_slice($memories, 0, 5),
            'total_count' => count($memories),
            'tokens_used' => 1000
        ];
    }
    
    private function buildLayerKey(string $tenantId, string $agentId, string $layer): string {
        return "stratify:{$tenantId}:{$agentId}:{$layer}";
    }
    
    private function buildSummaryKey(string $tenantId, string $layer): string {
        return "summary:{$tenantId}:{$layer}";
    }
    
    private function estimateTotalTokens(array $memories): int {
        $total = 0;
        foreach ($memories as $memory) {
            $total += $this->estimateTokens($memory['content'] ?? '');
        }
        return $total;
    }
    
    private function estimateTokens(string $content): int {
        return (int) ceil(strlen($content) / 4);
    }
}