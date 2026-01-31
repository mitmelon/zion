<?php
namespace ZionXMemory\Orchestrator;

use ZionXMemory\Memory\MindscapeMemory;
use ZionXMemory\Graph\GraphMemory;
use ZionXMemory\Gnosis\EpistemicState;
use ZionXMemory\Adaptive\AdaptiveMemory;
use ZionXMemory\Adaptive\ATLASPriority;
use ZionXMemory\Adaptive\HierarchicalCompression;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;

/**
 * HybridMemoryV2 - Enhanced orchestrator with adaptive memory
 * Integrates MIRAS, ATLAS, and hierarchical compression
 * Maintains substrate-only principle: NEVER enforces behavior
 * 
 * @package ZionXMemory\Orchestrator
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

class HybridMemoryV2 {
    // Core layers (v1)
    private MindscapeMemory $mindscape;
    private GraphMemory $graph;
    private EpistemicState $gnosis;
    
    // Adaptive extensions (v2)
    private AdaptiveMemory $adaptive;
    private ATLASPriority $priority;
    private HierarchicalCompression $compression;
    
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    private AuditInterface $audit;
    
    private bool $adaptiveEnabled = true;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AIAdapterInterface $ai,
        AuditInterface $audit,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->audit = $audit;
        
        // Initialize core layers
        $this->mindscape = new MindscapeMemory($storage, $ai, $audit);
        $this->graph = new GraphMemory($storage, $ai, $audit);
        $this->gnosis = new EpistemicState($storage, $ai, $audit);
        
        // Initialize adaptive layers
        $this->adaptive = new AdaptiveMemory($storage, $ai, $audit);
        $this->priority = new ATLASPriority($storage);
        $this->compression = new HierarchicalCompression($storage, $ai);
        
        $this->adaptiveEnabled = $config['enable_adaptive'] ?? true;
    }
    
    /**
     * Store memory with adaptive features (v2 enhanced)
     * Automatically computes surprise and manages retention
     */
    public function storeMemoryAdaptive(
        string $tenantId,
        string $agentId,
        array $data,
        array $surpriseSignal = []
    ): array {
        $timestamp = time();
        
        // Store in core layers (v1 functionality)
        $coreResult = $this->storeMemoryCore($tenantId, $agentId, $data);
        
        // If adaptive enabled, enhance with adaptive storage
        if ($this->adaptiveEnabled) {
            $adaptiveId = $this->adaptive->storeAdaptiveMemory(
                $tenantId,
                $agentId,
                $data,
                $surpriseSignal,
                [
                    'core_memory_id' => $coreResult['memory_id'],
                    'beliefs' => $coreResult['beliefs'],
                    'graph_nodes' => $coreResult['graph']['nodes_created'] ?? []
                ]
            );
            
            $coreResult['adaptive_id'] = $adaptiveId;
            $coreResult['surprise_score'] = $this->getMemorySurprise($tenantId, $adaptiveId);
        }
        
        return $coreResult;
    }
    
    /**
     * Build optimized context snapshot (v2 enhanced)
     * Uses ATLAS priority and hierarchical compression
     */
    public function buildOptimizedContext(
        string $tenantId,
        string $agentId,
        array $options = []
    ): array {
        $maxTokens = $options['max_tokens'] ?? 8000;
        $queryContext = $options['query_context'] ?? [];
        
        // Get base context from v1
        $baseContext = $this->buildContextCore($tenantId, $agentId, $options);
        
        if (!$this->adaptiveEnabled) {
            return $baseContext;
        }
        
        // Enhance with adaptive features
        $enhancedContext = $baseContext;
        
        // 1. ATLAS-prioritized memory selection
        $relevantMemories = $this->getRelevantMemories($tenantId, $agentId, $queryContext);
        $prioritized = $this->priority->rerankByImportance($relevantMemories, [
            'token_budget' => $maxTokens,
            'query_context' => $queryContext,
            'diversity_factor' => 0.3
        ]);
        
        // 2. Hierarchical compression based on surprise
        $surpriseScores = array_map(
            fn($m) => $m['surprise_score'] ?? 0.5,
            $prioritized
        );
        
        $hierarchicalSummary = $this->compression->createHierarchicalSummary(
            $prioritized,
            $surpriseScores
        );
        
        $enhancedContext['adaptive'] = [
            'prioritized_memories' => count($prioritized),
            'hierarchical_summary' => $hierarchicalSummary,
            'surprise_distribution' => $this->getSurpriseDistribution($surpriseScores),
            'compression_stats' => $this->compression->getCompressionRatio($tenantId)
        ];
        
        // 3. Add retention recommendations (not enforcements)
        $retentionStatus = $this->adaptive->getRetentionPolicyStatus($tenantId);
        $enhancedContext['retention_status'] = $retentionStatus;
        
        // 4. Add high-surprise memories explicitly
        $highSurprise = $this->adaptive->queryMemoryBySurprise(
            $tenantId,
            ['min' => 0.7, 'max' => 1.0],
            ['agent_id' => $agentId]
        );
        $enhancedContext['high_surprise_memories'] = array_slice($highSurprise, 0, 10);
        
        return $enhancedContext;
    }
    
    /**
     * Query with adaptive filtering
     */
    public function queryAdaptive(string $tenantId, array $query): array {
        // Core query
        $coreResults = $this->queryMemoryCore($tenantId, $query);
        
        if (!$this->adaptiveEnabled) {
            return $coreResults;
        }
        
        // Adaptive filtering
        if (isset($query['surprise_threshold'])) {
            $coreResults['adaptive_filtered'] = $this->adaptive->queryMemoryBySurprise(
                $tenantId,
                ['min' => $query['surprise_threshold']],
                $query['filters'] ?? []
            );
        }
        
        // ATLAS priority ranking
        if (isset($query['prioritize']) && $query['prioritize']) {
            $allResults = array_merge(
                $coreResults['narrative'] ?? [],
                $coreResults['adaptive_filtered'] ?? []
            );
            
            $coreResults['prioritized'] = $this->priority->rerankByImportance(
                $allResults,
                $query
            );
        }
        
        return $coreResults;
    }
    
    /**
     * Apply retention policy (provides recommendations only)
     */
    public function evaluateRetention(string $tenantId): array {
        $status = $this->adaptive->getRetentionPolicyStatus($tenantId);
        
        // Get compression candidates
        $compressionCandidates = array_filter(
            $status['forgetting_candidates'] ?? [],
            fn($c) => $c['age_days'] > 30
        );
        
        // Get promotion candidates
        $promotionCandidates = $this->identifyPromotionCandidates($tenantId);
        
        return [
            'status' => $status,
            'recommendations' => [
                'compress' => array_slice($compressionCandidates, 0, 20),
                'promote' => array_slice($promotionCandidates, 0, 10)
            ],
            'note' => 'These are recommendations only - agents decide whether to act'
        ];
    }
    
    /**
     * Update from usage patterns (ATLAS learning)
     */
    public function recordMemoryUsage(string $tenantId, array $accessLog): void {
        $this->priority->updateImportanceFromUsage($tenantId, $accessLog);
        
        $this->audit->log($tenantId, 'memory_usage_recorded', [
            'access_count' => count($accessLog)
        ], ['timestamp' => time()]);
    }
    
    /**
     * Configure adaptive features per tenant
     */
    public function configureAdaptive(string $tenantId, array $config): bool {
        $success = true;
        
        if (isset($config['retention_policy'])) {
            $success = $this->adaptive->updateRetentionPolicy(
                $tenantId,
                $config['retention_policy']
            ) && $success;
        }
        
        if (isset($config['surprise_weights'])) {
            // Store surprise computation weights
            $key = "adaptive_config:{$tenantId}:surprise_weights";
            $this->storage->write($key, $config['surprise_weights'], ['tenant' => $tenantId]);
        }
        
        if (isset($config['compression_strategy'])) {
            $key = "adaptive_config:{$tenantId}:compression";
            $this->storage->write($key, $config['compression_strategy'], ['tenant' => $tenantId]);
        }
        
        return $success;
    }
    
    /**
     * Get adaptive metrics
     */
    public function getAdaptiveMetrics(string $tenantId): array {
        $baseMetrics = $this->getHealthMetrics($tenantId);
        
        if (!$this->adaptiveEnabled) {
            return array_merge($baseMetrics, ['adaptive_enabled' => false]);
        }
        
        $retentionStatus = $this->adaptive->getRetentionPolicyStatus($tenantId);
        $compressionRatio = $this->compression->getCompressionRatio($tenantId);
        
        // Surprise distribution
        $surpriseStats = $this->getSurpriseStatistics($tenantId);
        
        return array_merge($baseMetrics, [
            'adaptive_enabled' => true,
            'memory_distribution' => $retentionStatus['distribution'],
            'compression_ratio' => $compressionRatio['overall_ratio'],
            'compression_by_level' => $compressionRatio['by_level'],
            'surprise_statistics' => $surpriseStats,
            'forgetting_candidates' => $retentionStatus['forgetting_candidates'],
            'storage_saved_bytes' => $this->calculateStorageSavings($compressionRatio)
        ]);
    }
    
    /**
     * Core storage (v1 compatibility)
     */
    private function storeMemoryCore(string $tenantId, string $agentId, array $data): array {
        $timestamp = time();
        
        // Store in Mindscape
        $memoryId = $this->mindscape->store($tenantId, $agentId, $data);
        
        // Extract and store in Graph
        $graphResults = [];
        if (isset($data['content']) && is_string($data['content'])) {
            $graphResults = $this->graph->extractAndStore(
                $tenantId,
                $data['content'],
                ['memory_id' => $memoryId, 'agent_id' => $agentId]
            );
        }
        
        // Record beliefs in Gnosis
        $beliefIds = [];
        if (isset($data['claims']) && is_array($data['claims'])) {
            foreach ($data['claims'] as $claim) {
                $beliefId = $this->gnosis->recordBelief(
                    $tenantId,
                    $claim['text'] ?? $claim,
                    $claim['confidence'] ?? ['min' => 0.3, 'max' => 0.7, 'mean' => 0.5],
                    [
                        'source' => 'memory',
                        'memory_id' => $memoryId,
                        'agent_id' => $agentId
                    ]
                );
                $beliefIds[] = $beliefId;
            }
        }
        
        return [
            'memory_id' => $memoryId,
            'graph' => $graphResults,
            'beliefs' => $beliefIds,
            'timestamp' => $timestamp
        ];
    }
    
    private function buildContextCore(string $tenantId, string $agentId, array $options): array {
        $maxTokens = $options['max_tokens'] ?? 8000;
        
        return [
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'timestamp' => time(),
            'narrative' => $this->mindscape->retrieve($tenantId, [
                'filters' => ['agent_id' => $agentId],
                'max_tokens' => (int)($maxTokens * 0.6)
            ]),
            'knowledge_graph' => $this->graph->queryGraph($tenantId, []),
            'epistemic_state' => $this->gnosis->getEpistemicSnapshot($tenantId, time()),
            'contradictions' => $this->findActiveContradictions($tenantId)
        ];
    }
    
    private function queryMemoryCore(string $tenantId, array $query): array {
        return [
            'narrative' => $this->mindscape->retrieve($tenantId, $query),
            'graph' => $this->graph->queryGraph($tenantId, $query['graph_pattern'] ?? []),
            'beliefs' => []
        ];
    }
    
    private function getRelevantMemories(string $tenantId, string $agentId, array $context): array {
        $pattern = "adaptive_memory:{$tenantId}:*";
        $all = $this->storage->query(['pattern' => $pattern]);
        
        return array_filter($all, fn($m) => $m['agent_id'] === $agentId);
    }
    
    private function getMemorySurprise(string $tenantId, string $memoryId): float {
        $key = "adaptive_memory:{$tenantId}:{$memoryId}";
        $memory = $this->storage->read($key);
        return $memory['surprise_score'] ?? 0.5;
    }
    
    private function getSurpriseDistribution(array $scores): array {
        $buckets = ['very_low' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'very_high' => 0];
        
        foreach ($scores as $score) {
            if ($score >= 0.8) $buckets['very_high']++;
            elseif ($score >= 0.6) $buckets['high']++;
            elseif ($score >= 0.4) $buckets['medium']++;
            elseif ($score >= 0.2) $buckets['low']++;
            else $buckets['very_low']++;
        }
        
        return $buckets;
    }
    
    private function getSurpriseStatistics(string $tenantId): array {
        $pattern = "adaptive_memory:{$tenantId}:*";
        $memories = $this->storage->query(['pattern' => $pattern]);
        
        $scores = array_map(fn($m) => $m['surprise_score'] ?? 0.5, $memories);
        
        if (empty($scores)) {
            return ['mean' => 0.5, 'median' => 0.5, 'std' => 0];
        }
        
        sort($scores);
        
        return [
            'mean' => array_sum($scores) / count($scores),
            'median' => $scores[(int)(count($scores) / 2)],
            'std' => $this->calculateStdDev($scores),
            'min' => min($scores),
            'max' => max($scores)
        ];
    }
    
    private function identifyPromotionCandidates(string $tenantId): array {
        return $this->adaptive->queryMemoryBySurprise(
            $tenantId,
            ['min' => 0.7],
            ['layer' => 'warm']
        );
    }
    
    private function findActiveContradictions(string $tenantId): array {
        $pattern = "gnosis:{$tenantId}:belief:*";
        $beliefs = $this->storage->query(['pattern' => $pattern]);
        
        $contradictions = [];
        foreach ($beliefs as $belief) {
            $c = $this->gnosis->findContradictions($tenantId, $belief, $beliefs);
            if (!empty($c)) {
                $contradictions[] = [
                    'belief_id' => $belief['id'],
                    'claim' => $belief['claim'],
                    'contradicts' => $c
                ];
            }
        }
        
        return $contradictions;
    }
    
    private function calculateStorageSavings(array $compressionRatio): int {
        $original = $compressionRatio['total_original_size'] ?? 0;
        $compressed = $compressionRatio['total_compressed_size'] ?? 0;
        return max(0, $original - $compressed);
    }
    
    private function calculateStdDev(array $values): float {
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        return sqrt(array_sum($squaredDiffs) / count($values));
    }
    
    private function getHealthMetrics(string $tenantId): array {
        return [
            'narrative_memories' => $this->countKeys($tenantId, 'mindscape'),
            'graph_nodes' => $this->countKeys($tenantId, 'graph:*:node'),
            'graph_edges' => $this->countKeys($tenantId, 'graph:*:edge'),
            'beliefs' => $this->countKeys($tenantId, 'gnosis:*:belief'),
            'audit_records' => $this->countKeys($tenantId, 'audit')
        ];
    }
    
    private function countKeys(string $tenantId, string $pattern): int {
        $results = $this->storage->query(['pattern' => "{$pattern}:{$tenantId}:*"]);
        return count($results);
    }
}