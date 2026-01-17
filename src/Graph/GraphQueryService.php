<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\GraphQueryServiceInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\GraphStoreInterface;

/**
 * GraphQueryService
 * High-level query interface for agents
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GraphQueryService implements GraphQueryServiceInterface {
    private GraphStoreInterface $graphStore;
    private StorageAdapterInterface $storage;
    
    public function __construct(
        GraphStoreInterface $graphStore,
        StorageAdapterInterface $storage
    ) {
        $this->graphStore = $graphStore;
        $this->storage = $storage;
    }
    
    /**
     * Get historical facts about a topic
     * Aggregates cross-session claims weighted by confidence
     */
    public function getHistoricalFacts(
        string $topic,
        string $tenantId,
        array $options = []
    ): array {
        $includeContradictions = $options['include_contradictions'] ?? true;
        $minConfidence = $options['min_confidence'] ?? 0.0;
        
        // Normalize topic to entity ID
        $entityId = $this->normalizeEntityId($topic, 'topic');
        
        // Get entity
        $entity = $this->graphStore->getEntity($tenantId, $entityId);
        if (!$entity) {
            return [
                'topic' => $topic,
                'found' => false,
                'facts' => []
            ];
        }
        
        // Get all relations
        $relations = $this->graphStore->getRelations($tenantId, $entityId);
        
        // Filter by confidence
        $relations = array_filter($relations, fn($r) => $r['confidence'] >= $minConfidence);
        
        // Group by relation type
        $factsByType = [];
        foreach ($relations as $relation) {
            $type = $relation['relation'];
            if (!isset($factsByType[$type])) {
                $factsByType[$type] = [];
            }
            $factsByType[$type][] = $relation;
        }
        
        // Build weighted consensus for each relation type
        $facts = [];
        foreach ($factsByType as $relationType => $rels) {
            $consensus = $this->buildConsensus($rels);
            $facts[] = [
                'relation' => $relationType,
                'consensus' => $consensus,
                'confidence' => $consensus['aggregate_confidence'],
                'source_count' => count($rels),
                'contradictions' => $includeContradictions ? $this->findLocalContradictions($rels) : []
            ];
        }
        
        // Sort by confidence
        usort($facts, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        
        return [
            'topic' => $topic,
            'entity_id' => $entityId,
            'found' => true,
            'entity_confidence' => $entity['aggregate_confidence'],
            'facts' => $facts,
            'sources' => $entity['sources'] ?? []
        ];
    }
    
    /**
     * Get entity relationships
     */
    public function getEntityRelationships(
        string $tenantId,
        string $entityId,
        array $filters = []
    ): array {
        $relations = $this->graphStore->getRelations($tenantId, $entityId);
        
        // Apply filters
        if (isset($filters['relation_type'])) {
            $relations = array_filter($relations, fn($r) => $r['relation'] === $filters['relation_type']);
        }
        
        if (isset($filters['min_confidence'])) {
            $relations = array_filter($relations, fn($r) => $r['confidence'] >= $filters['min_confidence']);
        }
        
        return $relations;
    }
    
    /**
     * Find path between two entities
     */
    public function findPath(
        string $tenantId,
        string $fromEntity,
        string $toEntity,
        int $maxDepth = 5
    ): ?array {
        // Breadth-first search
        $queue = [[$fromEntity]];
        $visited = [$fromEntity => true];
        
        while (!empty($queue) && count($queue[0]) <= $maxDepth) {
            $path = array_shift($queue);
            $current = end($path);
            
            if ($current === $toEntity) {
                return $this->buildPathResult($tenantId, $path);
            }
            
            $relations = $this->graphStore->getRelations($tenantId, $current);
            
            foreach ($relations as $relation) {
                $next = $relation['to'];
                
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $newPath = $path;
                    $newPath[] = $next;
                    $queue[] = $newPath;
                }
            }
        }
        
        return null; // No path found
    }
    
    /**
     * Get confidence-weighted consensus
     */
    public function getConsensus(
        string $tenantId,
        string $topic,
        array $options = []
    ): array {
        $facts = $this->getHistoricalFacts($topic, $tenantId, $options);
        
        if (!$facts['found']) {
            return [
                'topic' => $topic,
                'consensus_reached' => false
            ];
        }
        
        // Aggregate all facts
        $totalConfidence = 0;
        $factCount = 0;
        
        foreach ($facts['facts'] as $fact) {
            $totalConfidence += $fact['confidence'];
            $factCount++;
        }
        
        $avgConfidence = $factCount > 0 ? $totalConfidence / $factCount : 0;
        
        return [
            'topic' => $topic,
            'consensus_reached' => $avgConfidence >= 0.6,
            'aggregate_confidence' => $avgConfidence,
            'fact_count' => $factCount,
            'key_facts' => array_slice($facts['facts'], 0, 5)
        ];
    }
    
    /**
     * Build consensus from multiple relations
     */
    private function buildConsensus(array $relations): array {
        $totalConfidence = 0;
        $targets = [];
        
        foreach ($relations as $rel) {
            $target = $rel['to'];
            $confidence = $rel['confidence'];
            
            if (!isset($targets[$target])) {
                $targets[$target] = ['confidence' => 0, 'count' => 0];
            }
            
            $targets[$target]['confidence'] += $confidence;
            $targets[$target]['count']++;
            $totalConfidence += $confidence;
        }
        
        // Find most confident target
        $best = null;
        $bestScore = 0;
        
        foreach ($targets as $target => $data) {
            $score = $data['confidence'] / $data['count'];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $target;
            }
        }
        
        return [
            'target' => $best,
            'aggregate_confidence' => $totalConfidence / count($relations),
            'agreement_rate' => count($targets) === 1 ? 1.0 : ($targets[$best]['count'] / count($relations))
        ];
    }
    
    /**
     * Find contradictions within relation set
     */
    private function findLocalContradictions(array $relations): array {
        $contradictions = [];
        
        for ($i = 0; $i < count($relations); $i++) {
            for ($j = $i + 1; $j < count($relations); $j++) {
                if ($relations[$i]['to'] !== $relations[$j]['to']) {
                    $contradictions[] = [
                        'relation_1' => $relations[$i],
                        'relation_2' => $relations[$j],
                        'severity' => min($relations[$i]['confidence'], $relations[$j]['confidence'])
                    ];
                }
            }
        }
        
        return $contradictions;
    }
    
    /**
     * Build path result with relations
     */
    private function buildPathResult(string $tenantId, array $path): array {
        $result = [
            'path' => $path,
            'length' => count($path) - 1,
            'relations' => []
        ];
        
        for ($i = 0; $i < count($path) - 1; $i++) {
            $from = $path[$i];
            $to = $path[$i + 1];
            
            $relations = $this->graphStore->getRelations($tenantId, $from);
            $connecting = array_filter($relations, fn($r) => $r['to'] === $to);
            
            $result['relations'][] = !empty($connecting) ? reset($connecting) : null;
        }
        
        return $result;
    }
    
    private function normalizeEntityId(string $name, string $type): string {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        return "{$type}_{$normalized}";
    }
}