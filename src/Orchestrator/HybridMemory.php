<?php
namespace ZionXMemory\Orchestrator;

use ZionXMemory\Memory\MindscapeMemory;
use ZionXMemory\Graph\GraphMemory;
use ZionXMemory\Gnosis\EpistemicState;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;

/**
 * HybridMemory - Master orchestrator coordinating all memory layers
 * This is the primary interface for agents to interact with ZionXMemory
 * 
 * @package ZionXMemory\Orchestrator
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class HybridMemory {
    private MindscapeMemory $mindscape;
    private GraphMemory $graph;
    private EpistemicState $gnosis;
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    private AuditInterface $audit;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AIAdapterInterface $ai,
        AuditInterface $audit
    ) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->audit = $audit;
        
        // Initialize memory layers
        $this->mindscape = new MindscapeMemory($storage, $ai, $audit);
        $this->graph = new GraphMemory($storage, $ai, $audit);
        $this->gnosis = new EpistemicState($storage, $ai, $audit);
    }
    
    /**
     * Store a complete memory entry across all layers
     */
    public function storeMemory(string $tenantId, string $agentId, array $data): array {
        $timestamp = time();
        
        // 1. Store in Mindscape (narrative memory)
        $memoryId = $this->mindscape->store($tenantId, $agentId, $data);
        
        // 2. Extract and store in Graph (structured knowledge)
        $graphResults = [];
        if (isset($data['content']) && is_string($data['content'])) {
            $graphResults = $this->graph->extractAndStore(
                $tenantId,
                $data['content'],
                ['memory_id' => $memoryId, 'agent_id' => $agentId]
            );
        }
        
        // 3. Record epistemic beliefs if claims are present
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
        
        $this->audit->log($tenantId, 'hybrid_memory_store', [
            'memory_id' => $memoryId,
            'agent_id' => $agentId,
            'graph_nodes' => count($graphResults['nodes_created'] ?? []),
            'graph_edges' => count($graphResults['edges_created'] ?? []),
            'beliefs' => count($beliefIds)
        ], ['timestamp' => $timestamp]);
        
        return [
            'memory_id' => $memoryId,
            'graph' => $graphResults,
            'beliefs' => $beliefIds,
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Build comprehensive context snapshot for an agent
     * This is what agents receive when they need context
     */
    public function buildContextSnapshot(string $tenantId, string $agentId, array $options = []): array {
        $maxTokens = $options['max_tokens'] ?? 8000;
        $includeGraph = $options['include_graph'] ?? true;
        $includeBeliefs = $options['include_beliefs'] ?? true;
        $includeContradictions = $options['include_contradictions'] ?? true;
        
        $snapshot = [
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'timestamp' => time(),
            'narrative' => [],
            'knowledge_graph' => [],
            'epistemic_state' => [],
            'contradictions' => [],
            'metadata' => []
        ];
        
        // 1. Get narrative context from Mindscape
        $narrativeContext = $this->mindscape->retrieve($tenantId, [
            'filters' => ['agent_id' => $agentId],
            'include_context' => true,
            'max_tokens' => (int)($maxTokens * 0.6) // 60% to narrative
        ]);
        $snapshot['narrative'] = $narrativeContext;
        
        // 2. Get relevant knowledge graph nodes and relationships
        if ($includeGraph) {
            $graphContext = $this->graph->queryGraph($tenantId, [
                'node_pattern' => ['properties' => ['agent_id' => $agentId]],
                'edge_pattern' => []
            ]);
            $snapshot['knowledge_graph'] = $graphContext;
        }
        
        // 3. Get epistemic state (current beliefs)
        if ($includeBeliefs) {
            $epistemicSnapshot = $this->gnosis->getEpistemicSnapshot($tenantId, time());
            $snapshot['epistemic_state'] = $epistemicSnapshot;
        }
        
        // 4. Surface contradictions that need attention
        if ($includeContradictions) {
            $contradictions = $this->findActiveContradictions($tenantId);
            $snapshot['contradictions'] = $contradictions;
        }
        
        // 5. Add metadata
        $snapshot['metadata'] = [
            'context_built_at' => time(),
            'total_tokens_estimate' => $this->estimateTokens($snapshot),
            'layers_included' => ['mindscape', 'graph', 'gnosis']
        ];
        
        return $snapshot;
    }
    
    /**
     * Query across all memory layers
     */
    public function queryMemory(string $tenantId, array $query): array {
        $results = [
            'narrative' => [],
            'graph' => [],
            'beliefs' => []
        ];
        
        // Query narrative memory
        if ($query['layers']['mindscape'] ?? true) {
            $results['narrative'] = $this->mindscape->retrieve($tenantId, $query);
        }
        
        // Query graph memory
        if ($query['layers']['graph'] ?? true) {
            $results['graph'] = $this->graph->queryGraph($tenantId, $query['graph_pattern'] ?? []);
        }
        
        // Query epistemic state
        if ($query['layers']['gnosis'] ?? true) {
            $results['beliefs'] = $this->queryBeliefs($tenantId, $query);
        }
        
        return $results;
    }
    
    /**
     * Update belief state (used when agents learn or discover contradictions)
     */
    public function updateBelief(string $tenantId, string $beliefId, string $newState, string $reason, string $agentId): bool {
        $success = $this->gnosis->updateBeliefState($tenantId, $beliefId, $newState, $reason);
        
        if ($success) {
            $this->audit->log($tenantId, 'belief_updated', [
                'belief_id' => $beliefId,
                'new_state' => $newState,
                'reason' => $reason,
                'agent_id' => $agentId
            ], ['timestamp' => time()]);
        }
        
        return $success;
    }
    
    /**
     * Get memory lineage (full history of a memory)
     */
    public function getMemoryLineage(string $tenantId, string $memoryId): array {
        return [
            'mindscape_lineage' => $this->mindscape->getMemoryLineage($tenantId, $memoryId),
            'belief_history' => $this->getRelatedBeliefHistory($tenantId, $memoryId),
            'graph_evolution' => $this->getGraphEvolution($tenantId, $memoryId)
        ];
    }
    
    /**
     * Find active contradictions across all layers
     */
    private function findActiveContradictions(string $tenantId): array {
        // Get contradictions from Gnosis
        $pattern = "gnosis:{$tenantId}:belief:*";
        $beliefs = $this->storage->query(['pattern' => $pattern]);
        
        $contradictions = [];
        foreach ($beliefs as $belief) {
            $beliefContradictions = $this->gnosis->findContradictions($tenantId, $belief['id']);
            if (!empty($beliefContradictions)) {
                $contradictions[] = [
                    'belief_id' => $belief['id'],
                    'claim' => $belief['claim'],
                    'contradicts' => $beliefContradictions
                ];
            }
        }
        
        return $contradictions;
    }
    
    /**
     * Query beliefs with filters
     */
    private function queryBeliefs(string $tenantId, array $query): array {
        $pattern = "gnosis:{$tenantId}:belief:*";
        $beliefs = $this->storage->query(['pattern' => $pattern]);
        
        // Apply filters
        if (isset($query['belief_state'])) {
            $beliefs = array_filter($beliefs, fn($b) => $b['state'] === $query['belief_state']);
        }
        
        if (isset($query['min_confidence'])) {
            $beliefs = array_filter($beliefs, fn($b) => $b['confidence']['mean'] >= $query['min_confidence']);
        }
        
        return array_values($beliefs);
    }
    
    /**
     * Get belief history related to a memory
     */
    private function getRelatedBeliefHistory(string $tenantId, string $memoryId): array {
        $pattern = "gnosis:{$tenantId}:belief:*";
        $beliefs = $this->storage->query(['pattern' => $pattern]);
        
        $related = array_filter($beliefs, function($belief) use ($memoryId) {
            return isset($belief['provenance']['memory_id']) 
                && $belief['provenance']['memory_id'] === $memoryId;
        });
        
        $history = [];
        foreach ($related as $belief) {
            $history[] = $this->gnosis->getBeliefHistory($tenantId, $belief['id']);
        }
        
        return $history;
    }
    
    /**
     * Get graph evolution related to a memory
     */
    private function getGraphEvolution(string $tenantId, string $memoryId): array {
        // Find graph nodes created from this memory
        $pattern = "graph:{$tenantId}:node:*";
        $nodes = $this->storage->query(['pattern' => $pattern]);
        
        $relatedNodes = array_filter($nodes, function($node) use ($memoryId) {
            return isset($node['properties']['context']['memory_id']) 
                && $node['properties']['context']['memory_id'] === $memoryId;
        });
        
        $evolution = [];
        foreach ($relatedNodes as $node) {
            $evolution[$node['id']] = $this->graph->getTemporalHistory($tenantId, $node['id']);
        }
        
        return $evolution;
    }
    
    /**
     * Estimate total tokens in snapshot
     */
    private function estimateTokens(array $snapshot): int {
        $json = json_encode($snapshot);
        return (int) ceil(strlen($json) / 4);
    }
    
    /**
     * Get system health metrics
     */
    public function getHealthMetrics(string $tenantId): array {
        return [
            'narrative_memories' => $this->countKeys($tenantId, 'mindscape'),
            'graph_nodes' => $this->countKeys($tenantId, 'graph:*:node'),
            'graph_edges' => $this->countKeys($tenantId, 'graph:*:edge'),
            'beliefs' => $this->countKeys($tenantId, 'gnosis:*:belief'),
            'audit_records' => $this->countKeys($tenantId, 'audit'),
            'storage_backend' => get_class($this->storage),
            'ai_provider' => $this->ai->getModelInfo()['provider'] ?? 'unknown'
        ];
    }
    
    private function countKeys(string $tenantId, string $pattern): int {
        $results = $this->storage->query(['pattern' => "{$pattern}:{$tenantId}:*"]);
        return count($results);
    }
}