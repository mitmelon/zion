<?php
namespace ZionXMemory\Contracts;

/**
 * MemoryDecayInterface
 * Epistemic decay, not deletion
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface MemoryDecayInterface {
    /**
     * Apply epistemic decay to claims
     */
    public function applyDecay(
        string $tenantId,
        array $options = []
    ): array;
    
    /**
     * Calculate influence score
     */
    public function calculateInfluence(
        string $tenantId,
        string $claimId
    ): float;
    
    /**
     * Get decay statistics
     */
    public function getDecayStats(string $tenantId): array;
}