<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\GraphStoreInterface;

/**
 * GraphStore
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GraphStore implements GraphStoreInterface {
    private StorageAdapterInterface $storage;
    
    public function __construct(StorageAdapterInterface $storage) {
        $this->storage = $storage;
    }
    
    public function addEntity(
        string $tenantId,
        string $entityId,
        string $type,
        array $attributes = []
    ): void {
        $key = $this->buildEntityKey($tenantId, $entityId);
        
        // Check if exists
        $existing = $this->storage->read($key);
        
        if ($existing) {
            // Update existing entity
            $entity = GraphEntity::fromArray($existing);
            
            if (isset($attributes['source_claim']) && isset($attributes['confidence'])) {
                $entity->addSource($attributes['source_claim'], $attributes['confidence']);
            }
            
            $entity->attributes = array_merge($entity->attributes, $attributes);
            $entity->updatedAt = time();
        } else {
            // Create new entity
            $entity = new GraphEntity($entityId, $tenantId, $type, $attributes);
            
            if (isset($attributes['source_claim']) && isset($attributes['confidence'])) {
                $entity->addSource($attributes['source_claim'], $attributes['confidence']);
            }
        }
        
        $this->storage->write($key, $entity->toArray(), [
            'tenant' => $tenantId,
            'type' => 'graph_entity'
        ]);
        
        // Update index
        $this->updateEntityIndex($tenantId, $entityId, $type);
    }
    
    public function addRelation(
        string $tenantId,
        string $from,
        string $relation,
        string $to,
        array $meta = []
    ): void {
        $relationObj = new GraphRelation($tenantId, $from, $relation, $to, $meta);
        
        if (isset($meta['source_claim']) && isset($meta['confidence'])) {
            $relationObj->addSource($meta['source_claim'], $meta['confidence']);
        }
        
        $key = $this->buildRelationKey($tenantId, $relationObj->id);
        $this->storage->write($key, $relationObj->toArray(), [
            'tenant' => $tenantId,
            'type' => 'graph_relation'
        ]);
        
        // Update relation indices
        $this->updateRelationIndex($tenantId, $from, $relationObj->id);
        $this->updateRelationIndex($tenantId, $to, $relationObj->id);
    }
    
    public function query(array $pattern): array {
        // Pattern matching on graph
        $tenantId = $pattern['tenant_id'] ?? null;
        if (!$tenantId) {
            return [];
        }
        
        $results = [];
        
        if (isset($pattern['entity_type'])) {
            $indexKey = "graph:index:{$tenantId}:type:{$pattern['entity_type']}";
            $entityIds = $this->storage->getSetMembers($indexKey);
            
            foreach ($entityIds as $entityId) {
                $entity = $this->getEntity($tenantId, $entityId);
                if ($entity) {
                    $results[] = $entity;
                }
            }
        }
        
        return $results;
    }
    
    public function getEntity(string $tenantId, string $entityId): ?array {
        $key = $this->buildEntityKey($tenantId, $entityId);
        return $this->storage->read($key);
    }
    
    public function getRelations(string $tenantId, string $entityId): array {
        $indexKey = "graph:index:{$tenantId}:relations:{$entityId}";
        $relationIds = $this->storage->getSetMembers($indexKey);
        
        $relations = [];
        foreach ($relationIds as $relId) {
            $key = $this->buildRelationKey($tenantId, $relId);
            $relation = $this->storage->read($key);
            if ($relation) {
                $relations[] = $relation;
            }
        }
        
        return $relations;
    }
    
    public function entityExists(string $tenantId, string $entityId): bool {
        $key = $this->buildEntityKey($tenantId, $entityId);
        return $this->storage->exists($key);
    }
    
    private function buildEntityKey(string $tenantId, string $entityId): string {
        return "graph:entity:{$tenantId}:{$entityId}";
    }
    
    private function buildRelationKey(string $tenantId, string $relationId): string {
        return "graph:relation:{$tenantId}:{$relationId}";
    }
    
    private function updateEntityIndex(string $tenantId, string $entityId, string $type): void {
        $indexKey = "graph:index:{$tenantId}:type:{$type}";
        $this->storage->addToSet($indexKey, $entityId, ['tenant' => $tenantId]);
    }
    
    private function updateRelationIndex(string $tenantId, string $entityId, string $relationId): void {
        $indexKey = "graph:index:{$tenantId}:relations:{$entityId}";
        $this->storage->addToSet($indexKey, $relationId, ['tenant' => $tenantId]);
    }
}