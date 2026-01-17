<?php
namespace ZionXMemory\Contracts;

/**
 * GraphStoreInterface
 * Storage contract for knowledge graph entities and relations
 * CRITICAL: Graph is DERIVED from memory, not a replacement
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface GraphStoreInterface {
    /**
     * Add entity to knowledge graph
     */
    public function addEntity(
        string $tenantId,
        string $entityId,
        string $type,
        array $attributes = []
    ): void;
    
    /**
     * Add relation between entities
     */
    public function addRelation(
        string $tenantId,
        string $from,
        string $relation,
        string $to,
        array $meta = []
    ): void;
    
    /**
     * Query graph with pattern matching
     */
    public function query(array $pattern): array;
    
    /**
     * Get entity with all relations
     */
    public function getEntity(string $tenantId, string $entityId): ?array;
    
    /**
     * Get all relations for entity
     */
    public function getRelations(string $tenantId, string $entityId): array;
    
    /**
     * Check if entity exists
     */
    public function entityExists(string $tenantId, string $entityId): bool;
}