<?php
namespace ZionXMemory\Contracts;

/**
 * GraphQueryServiceInterface
 * High-level query interface for agents
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface GraphQueryServiceInterface {
    /**
     * Get historical facts about a topic
     * Aggregates cross-session claims weighted by confidence
     */
    public function getHistoricalFacts(
        string $topic,
        string $tenantId,
        array $options = []
    ): array;
    
    /**
     * Get entity relationships
     */
    public function getEntityRelationships(
        string $tenantId,
        string $entityId,
        array $filters = []
    ): array;
    
    /**
     * Get path between two entities
     */
    public function findPath(
        string $tenantId,
        string $fromEntity,
        string $toEntity,
        int $maxDepth = 5
    ): ?array;
    
    /**
     * Get confidence-weighted consensus
     */
    public function getConsensus(
        string $tenantId,
        string $topic,
        array $options = []
    ): array;
}
