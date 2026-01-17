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

interface GraphConsistencyCheckerInterface {
    /**
     * Detect conflicts for an entity
     * Returns structured conflict objects (NOT text)
     */
    public function detectConflicts(string $tenantId, string $entityId): array;
    
    /**
     * Check consistency across graph
     */
    public function checkConsistency(string $tenantId): array;
    
    /**
     * Get contradiction summary
     */
    public function getContradictionSummary(string $tenantId): array;
    
    /**
     * Validate relation consistency
     */
    public function validateRelation(
        string $tenantId,
        string $from,
        string $relation,
        string $to
    ): array;
}