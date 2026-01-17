<?php
namespace ZionXMemory\Contracts;

/**
 * EpistemicStatusInterface
 * CRITICAL: Tracks epistemic nature of knowledge
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface EpistemicStatusInterface {
    const STATUS_HYPOTHESIS = 'hypothesis';
    const STATUS_EVIDENCE = 'evidence';
    const STATUS_ASSUMPTION = 'assumption';
    const STATUS_DECISION = 'decision';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CONTESTED = 'contested';
    
    /**
     * Set epistemic status for claim
     */
    public function setStatus(
        string $tenantId,
        string $claimId,
        string $status,
        array $justification
    ): void;
    
    /**
     * Get claims by epistemic status
     */
    public function getClaimsByStatus(
        string $tenantId,
        string $status
    ): array;
    
    /**
     * Track status transitions
     */
    public function trackTransition(
        string $tenantId,
        string $claimId,
        string $fromStatus,
        string $toStatus,
        string $reason
    ): void;
    
    /**
     * Query: Are we reasoning from facts or assumptions?
     */
    public function getReasoningBasis(
        string $tenantId,
        array $claimIds
    ): array;
}