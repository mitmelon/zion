<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface GraphMemoryAdapter
 * 
 * Defines the contract for graph-based memory storage (Graph RAG).
 * Stores structured facts and relationships extracted from AI responses.
 * Supports graph queries for multi-agent reasoning.
 * 
 * @package Zion\Memory\Contracts
 */
interface GraphMemoryAdapter
{
    /**
     * Store a fact (node) in the graph.
     *
     * @param string $tenantId Unique tenant identifier for isolation
     * @param array $fact Fact data including entity, type, attributes, timestamps
     * @return string Unique fact/node ID
     */
    public function storeFact(string $tenantId, array $fact): string;

    /**
     * Store a relationship (edge) between two facts/entities.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $fromEntityId Source entity ID
     * @param string $toEntityId Target entity ID
     * @param string $relationType Type of relationship (e.g., "has_account", "works_at")
     * @param array $metadata Additional relationship metadata
     * @return string Unique relationship ID
     */
    public function storeRelationship(
        string $tenantId,
        string $fromEntityId,
        string $toEntityId,
        string $relationType,
        array $metadata = []
    ): string;

    /**
     * Query facts by entity type.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $entityType Type of entity to query
     * @param array $filters Additional filters
     * @return array Array of matching facts
     */
    public function queryByType(string $tenantId, string $entityType, array $filters = []): array;

    /**
     * Query facts by entity name/identifier.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $entityName Name or identifier of the entity
     * @return array|null Fact data or null if not found
     */
    public function queryByEntity(string $tenantId, string $entityName): ?array;

    /**
     * Get all relationships for an entity.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $entityId Entity ID to get relationships for
     * @param string|null $relationType Filter by relationship type
     * @return array Array of relationships
     */
    public function getRelationships(string $tenantId, string $entityId, ?string $relationType = null): array;

    /**
     * Find related entities within N degrees of separation.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $entityId Starting entity ID
     * @param int $depth Maximum degrees of separation
     * @param array $relationTypes Filter by relationship types
     * @return array Array of related entities with path information
     */
    public function findRelatedEntities(
        string $tenantId,
        string $entityId,
        int $depth = 2,
        array $relationTypes = []
    ): array;

    /**
     * Update a fact's attributes.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $factId Fact ID to update
     * @param array $attributes New attributes to merge
     * @return bool True on success
     */
    public function updateFact(string $tenantId, string $factId, array $attributes): bool;

    /**
     * Delete a fact and its relationships.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $factId Fact ID to delete
     * @return bool True on success
     */
    public function deleteFact(string $tenantId, string $factId): bool;

    /**
     * Search facts by attribute values.
     *
     * @param string $tenantId Unique tenant identifier
     * @param array $criteria Search criteria as key-value pairs
     * @return array Array of matching facts
     */
    public function searchFacts(string $tenantId, array $criteria): array;

    /**
     * Get all facts for a tenant (with pagination).
     *
     * @param string $tenantId Unique tenant identifier
     * @param int $limit Maximum number of facts
     * @param int $offset Starting offset
     * @return array Array of facts
     */
    public function getAllFacts(string $tenantId, int $limit = 100, int $offset = 0): array;

    /**
     * Execute a custom graph query.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $query Query string (adapter-specific syntax)
     * @param array $params Query parameters
     * @return array Query results
     */
    public function executeQuery(string $tenantId, string $query, array $params = []): array;

    /**
     * Check if the graph storage is healthy and accessible.
     *
     * @return bool True if healthy
     */
    public function healthCheck(): bool;
}
