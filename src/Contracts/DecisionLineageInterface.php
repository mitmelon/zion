<?php
namespace ZionXMemory\Contracts;

/**
 * DecisionLineageInterface
 * Tracks decision provenance and reasoning chains
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface DecisionLineageInterface {
    /**
     * Record decision with full lineage
     */
    public function recordDecision(
        string $tenantId,
        string $decisionId,
        array $decision
    ): void;
    
    /**
     * Get decision lineage
     */
    public function getDecisionLineage(
        string $tenantId,
        string $decisionId
    ): array;
    
    /**
     * Get all decisions depending on a claim
     */
    public function getDownstreamDecisions(
        string $tenantId,
        string $claimId
    ): array;
    
    /**
     * Generate decision report
     */
    public function generateDecisionReport(
        string $tenantId,
        string $decisionId
    ): array;
}