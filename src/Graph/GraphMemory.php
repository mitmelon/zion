<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\GraphAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;

/**
 * GraphMemory - Structured Knowledge Memory
 * Temporal property graph with entity/relationship extraction
 * Supports belief revision and contradiction detection
 * Tracks epistemic states and confidence levels
 * Versioned nodes and edges for temporal reasoning
 * Integrates with AI adapters for extraction and analysis
 * Multi-tenant support with isolated graphs
 * Audit logging for all graph operations
 * Designed for production-grade applications
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GraphMemory implements GraphAdapterInterface {
    private $storage; // Graph storage backend
    private AIAdapterInterface $ai;
    private AuditInterface $audit;
    
    public function __construct($storage, AIAdapterInterface $ai, AuditInterface $audit) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->audit = $audit;
    }
    
    public function addNode(string $tenantId, string $nodeId, string $type, array $properties, int $timestamp): bool {
        $node = new BeliefNode($nodeId, $type, $properties, $timestamp);
        
        $key = $this->buildNodeKey($tenantId, $nodeId);
        $this->storage->write($key, $node->toArray(), [
            'tenant' => $tenantId,
            'type' => 'node',
            'timestamp' => $timestamp
        ]);
        
        $this->audit->log($tenantId, 'graph_add_node', [
            'node_id' => $nodeId,
            'type' => $type
        ], ['timestamp' => $timestamp]);
        
        return true;
    }
    
    public function addEdge(string $tenantId, string $fromId, string $toId, string $relationType, array $properties, int $timestamp): bool {
        $edgeId = $this->generateEdgeId($fromId, $toId, $relationType);
        $edge = new RelationEdge($edgeId, $fromId, $toId, $relationType, $properties, $timestamp);
        
        $key = $this->buildEdgeKey($tenantId, $edgeId);
        $this->storage->write($key, $edge->toArray(), [
            'tenant' => $tenantId,
            'type' => 'edge',
            'timestamp' => $timestamp
        ]);
        
        $this->audit->log($tenantId, 'graph_add_edge', [
            'edge_id' => $edgeId,
            'from' => $fromId,
            'to' => $toId,
            'relation' => $relationType
        ], ['timestamp' => $timestamp]);
        
        return true;
    }
    
    /**
     * Extract entities and relationships from content using AI
     */
    public function extractAndStore(string $tenantId, string $content, array $context): array {
        // Extract entities
        $entities = $this->ai->extractEntities($content);
        
        // Extract relationships
        $relationships = $this->ai->extractRelationships($content);
        
        $timestamp = time();
        $results = [
            'nodes_created' => [],
            'edges_created' => []
        ];
        
        // Store entities as nodes
        foreach ($entities as $entity) {
            $nodeId = $this->generateNodeId($entity['name'], $entity['type']);
            
            $this->addNode($tenantId, $nodeId, $entity['type'], [
                'name' => $entity['name'],
                'attributes' => $entity['attributes'] ?? [],
                'source_content' => $content,
                'context' => $context
            ], $timestamp);
            
            $results['nodes_created'][] = $nodeId;
        }
        
        // Store relationships as edges
        foreach ($relationships as $relation) {
            $fromId = $this->generateNodeId($relation['from'], $relation['from_type']);
            $toId = $this->generateNodeId($relation['to'], $relation['to_type']);
            
            $this->addEdge($tenantId, $fromId, $toId, $relation['type'], [
                'confidence' => $relation['confidence'] ?? 0.8,
                'source_content' => $content,
                'context' => $context
            ], $timestamp);
            
            $results['edges_created'][] = "{$fromId}->{$toId}";
        }
        
        return $results;
    }
    
    public function queryGraph(string $tenantId, array $pattern): array {
        $nodePattern = $pattern['node_pattern'] ?? null;
        $edgePattern = $pattern['edge_pattern'] ?? null;
        
        $results = [];
        
        if ($nodePattern) {
            $results['nodes'] = $this->queryNodes($tenantId, $nodePattern);
        }
        
        if ($edgePattern) {
            $results['edges'] = $this->queryEdges($tenantId, $edgePattern);
        }
        
        return $results;
    }
    
    public function getTemporalHistory(string $tenantId, string $nodeId): array {
        // Get all versions of a node over time
        $key = $this->buildNodeKey($tenantId, $nodeId);
        $versionsPattern = $key . ':version:*';
        
        $versions = $this->storage->query(['pattern' => $versionsPattern]);
        
        usort($versions, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        return $versions;
    }
    
    public function findContradictions(string $tenantId, string $nodeId): array {
        $history = $this->getTemporalHistory($tenantId, $nodeId);
        
        $contradictions = [];
        
        // Compare properties across versions
        for ($i = 0; $i < count($history) - 1; $i++) {
            $current = $history[$i];
            $next = $history[$i + 1];
            
            $conflicts = $this->compareProperties($current['properties'], $next['properties']);
            
            if (!empty($conflicts)) {
                $contradictions[] = [
                    'timestamp_1' => $current['timestamp'],
                    'timestamp_2' => $next['timestamp'],
                    'conflicts' => $conflicts
                ];
            }
        }
        
        return $contradictions;
    }
    
    /**
     * Track belief evolution over time
     */
    public function trackBeliefEvolution(string $tenantId, string $beliefId): array {
        $history = $this->getTemporalHistory($tenantId, $beliefId);
        
        $evolution = [];
        
        foreach ($history as $version) {
            $evolution[] = [
                'timestamp' => $version['timestamp'],
                'state' => $version['properties']['epistemic_state'] ?? 'unknown',
                'confidence' => $version['properties']['confidence'] ?? [],
                'changes' => $version['properties']['changes'] ?? []
            ];
        }
        
        return $evolution;
    }
    
    private function queryNodes(string $tenantId, array $pattern): array {
        $keyPattern = $this->buildNodeKey($tenantId, '*');
        $allNodes = $this->storage->query(['pattern' => $keyPattern]);
        
        return array_filter($allNodes, function($node) use ($pattern) {
            if (isset($pattern['type']) && $node['type'] !== $pattern['type']) {
                return false;
            }
            
            if (isset($pattern['properties'])) {
                foreach ($pattern['properties'] as $key => $value) {
                    if (!isset($node['properties'][$key]) || $node['properties'][$key] !== $value) {
                        return false;
                    }
                }
            }
            
            return true;
        });
    }
    
    private function queryEdges(string $tenantId, array $pattern): array {
        $keyPattern = $this->buildEdgeKey($tenantId, '*');
        $allEdges = $this->storage->query(['pattern' => $keyPattern]);
        
        return array_filter($allEdges, function($edge) use ($pattern) {
            if (isset($pattern['relation_type']) && $edge['relation_type'] !== $pattern['relation_type']) {
                return false;
            }
            
            if (isset($pattern['from']) && $edge['from_id'] !== $pattern['from']) {
                return false;
            }
            
            if (isset($pattern['to']) && $edge['to_id'] !== $pattern['to']) {
                return false;
            }
            
            return true;
        });
    }
    
    private function compareProperties(array $props1, array $props2): array {
        $conflicts = [];
        
        foreach ($props1 as $key => $value1) {
            if (isset($props2[$key])) {
                $value2 = $props2[$key];
                
                if ($value1 !== $value2 && $key !== 'timestamp' && $key !== 'updated_at') {
                    $conflicts[] = [
                        'property' => $key,
                        'old_value' => $value1,
                        'new_value' => $value2
                    ];
                }
            }
        }
        
        return $conflicts;
    }
    
    private function buildNodeKey(string $tenantId, string $nodeId): string {
        return "graph:{$tenantId}:node:{$nodeId}";
    }
    
    private function buildEdgeKey(string $tenantId, string $edgeId): string {
        return "graph:{$tenantId}:edge:{$edgeId}";
    }
    
    private function generateNodeId(string $name, string $type): string {
        return 'node_' . hash('sha256', $type . ':' . $name);
    }
    
    private function generateEdgeId(string $fromId, string $toId, string $relationType): string {
        return 'edge_' . hash('sha256', $fromId . ':' . $relationType . ':' . $toId);
    }
}