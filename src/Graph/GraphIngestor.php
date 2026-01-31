<?php

namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;
use ZionXMemory\Contracts\GraphIngestorInterface;
use ZionXMemory\Contracts\GraphStoreInterface;

/**
 * GraphIngestor
 * Transforms memory claims into knowledge graph
 * IDEMPOTENT: Safe to call multiple times
 * DERIVED: Graph is materialized from memory, not replacing it
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GraphIngestor implements GraphIngestorInterface {
    private GraphStoreInterface $graphStore;
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    private AuditInterface $audit;
    private array $ingestedClaims = [];
    
    public function __construct(
        GraphStoreInterface $graphStore,
        StorageAdapterInterface $storage,
        AIAdapterInterface $ai,
        AuditInterface $audit
    ) {
        $this->graphStore = $graphStore;
        $this->storage = $storage;
        $this->ai = $ai;
        $this->audit = $audit;
    }
    
    /**
     * Ingest claims from session into graph
     * IDEMPOTENT: Tracks what's been ingested
     */
    public function ingestFromSession(string $tenantId, string $sessionId): array {
        $ingestionKey = "{$tenantId}:{$sessionId}";
        
        // Check if already ingested
        if (isset($this->ingestedClaims[$ingestionKey])) {
            return ['status' => 'already_ingested', 'entities' => 0, 'relations' => 0];
        }
        
        // Get session claims from memory
        $claims = $this->getSessionClaims($tenantId, $sessionId);
        
        $stats = [
            'entities_created' => 0,
            'relations_created' => 0,
            'claims_processed' => 0
        ];
        
        // Batch extract structures
        $structures = $this->extractStructureBatch($claims);

        foreach ($claims as $i => $claim) {
            $result = $this->ingestClaim($tenantId, $claim, [
                'session_id' => $sessionId
            ], $structures[$i] ?? null);
            
            $stats['entities_created'] += $result['entities'];
            $stats['relations_created'] += $result['relations'];
            $stats['claims_processed']++;
        }
        
        // Mark as ingested
        $this->ingestedClaims[$ingestionKey] = time();
        
        $this->audit->log($tenantId, 'graph_ingestion', [
            'session_id' => $sessionId,
            'stats' => $stats
        ], ['timestamp' => time()]);
        
        return $stats;
    }
    
    /**
     * Ingest single claim into graph
     * Normalizes claims into entities and relations
     */
    public function ingestClaim(string $tenantId, array $claim, array $context, ?array $preExtracted = null): array {
        $claimId = $claim['id'] ?? uniqid('claim_');
        $confidence = $claim['confidence']['mean'] ?? 0.5;
        
        // Extract entities and relations from claim
        $extracted = $preExtracted ?? $this->extractStructure($claim);
        
        $stats = ['entities' => 0, 'relations' => 0];
        
        // Create entities
        foreach ($extracted['entities'] as $entity) {
            $entityId = $this->normalizeEntityId($entity['name'], $entity['type']);
            
            if (!$this->graphStore->entityExists($tenantId, $entityId)) {
                $this->graphStore->addEntity(
                    $tenantId,
                    $entityId,
                    $entity['type'],
                    array_merge($entity['attributes'], [
                        'source_claim' => $claimId,
                        'confidence' => $confidence,
                        'session_id' => $context['session_id'] ?? null
                    ])
                );
                $stats['entities']++;
            }
        }
        
        // Create relations
        foreach ($extracted['relations'] as $relation) {
            $fromId = $this->normalizeEntityId($relation['from'], $relation['from_type']);
            $toId = $this->normalizeEntityId($relation['to'], $relation['to_type']);
            
            $this->graphStore->addRelation(
                $tenantId,
                $fromId,
                $relation['type'],
                $toId,
                [
                    'confidence' => $confidence,
                    'source_claim' => $claimId,
                    'context' => $context
                ]
            );
            $stats['relations']++;
        }
        
        return $stats;
    }
    
    /**
     * Get ingestion statistics
     */
    public function getIngestionStats(string $tenantId): array {
        // Count entities and relations
        $entitiesCount = $this->storage->count([
            'pattern' => "graph:entity:{$tenantId}:*"
        ]);
        
        $relationsCount = $this->storage->count([
            'pattern' => "graph:relation:{$tenantId}:*"
        ]);
        
        return [
            'total_entities' => $entitiesCount,
            'total_relations' => $relationsCount,
            'sessions_ingested' => count($this->ingestedClaims)
        ];
    }
    
    /**
     * Batch extract structures for multiple claims
     */
    private function extractStructureBatch(array $claims): array {
        $texts = [];
        foreach ($claims as $i => $claim) {
            $texts[$i] = $claim['claim'] ?? $claim['text'] ?? '';
        }

        try {
            $entitiesBatch = $this->ai->extractEntitiesBatch($texts);
            $relationsBatch = $this->ai->extractRelationshipsBatch($texts);
        } catch (\Exception $e) {
            return []; // Fallback to individual extraction
        }

        $results = [];
        foreach ($claims as $i => $claim) {
            $topic = $claim['topic'] ?? 'unknown';
            $entities = $entitiesBatch[$i] ?? [];
            $relations = $relationsBatch[$i] ?? [];

            // Ensure topic is an entity
            if (!empty($topic) && $topic !== 'unknown') {
                $entities[] = [
                    'name' => $topic,
                    'type' => 'topic',
                    'attributes' => []
                ];
            }

            $results[$i] = [
                'entities' => $entities,
                'relations' => $relations
            ];
        }

        return $results;
    }

    /**
     * Extract entities and relations from claim
     * Uses AI for structured extraction
     */
    private function extractStructure(array $claim): array {
        $claimText = $claim['claim'] ?? $claim['text'] ?? '';
        $topic = $claim['topic'] ?? 'unknown';
        
        // Use AI to extract structure
        try {
            $entities = $this->ai->extractEntities($claimText);
            $relations = $this->ai->extractRelationships($claimText);
            
            // Ensure topic is an entity
            if (!empty($topic) && $topic !== 'unknown') {
                $entities[] = [
                    'name' => $topic,
                    'type' => 'topic',
                    'attributes' => []
                ];
            }
            
            return [
                'entities' => $entities,
                'relations' => $relations
            ];
        } catch (\Exception $e) {
            // Fallback to simple extraction
            return $this->simpleExtraction($claimText, $topic);
        }
    }
    
    /**
     * Simple extraction fallback
     */
    private function simpleExtraction(string $text, string $topic): array {
        // Basic keyword extraction
        $words = str_word_count(strtolower($text), 1);
        $keywords = array_filter($words, fn($w) => strlen($w) > 4);
        
        $entities = [
            ['name' => $topic, 'type' => 'topic', 'attributes' => []]
        ];
        
        foreach (array_slice($keywords, 0, 3) as $keyword) {
            $entities[] = [
                'name' => $keyword,
                'type' => 'concept',
                'attributes' => []
            ];
        }
        
        $relations = [];
        if (!empty($keywords)) {
            $relations[] = [
                'from' => $topic,
                'from_type' => 'topic',
                'to' => $keywords[0],
                'to_type' => 'concept',
                'type' => 'relates_to'
            ];
        }
        
        return [
            'entities' => $entities,
            'relations' => $relations
        ];
    }
    
    /**
     * Get claims from session
     */
    private function getSessionClaims(string $tenantId, string $sessionId): array {
        $key = "session:{$tenantId}:{$sessionId}:claims";
        $claims = $this->storage->read($key);
        
        return $claims ?? [];
    }
    
    /**
     * Normalize entity ID
     */
    private function normalizeEntityId(string $name, string $type): string {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        return "{$type}_{$normalized}";
    }
}