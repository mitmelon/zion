<?php
namespace ZionXMemory\Contracts;

/**
 * AdaptiveMemoryInterface
 * MIRAS-inspired adaptive memory module
 * Provides importance weighting and retention gating WITHOUT enforcing behavior
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

interface AdaptiveMemoryInterface {
    /**
     * Store memory with adaptive signals
     */
    public function storeAdaptiveMemory(
        string $tenantId,
        string $agentId,
        array $content,
        array $surpriseSignal,
        array $metadata
    ): string;
    
    /**
     * Compute surprise/importance score for new memory
     */
    public function computeSurprise(array $existingMemories, array $newMemory): array;
    
    /**
     * Promote memory to active/hot layer
     */
    public function promoteToActiveMemory(string $tenantId, string $memoryUnitId, string $reason): bool;
    
    /**
     * Demote memory to compressed/cold layer
     */
    public function demoteToCompressedMemory(string $tenantId, string $memoryUnitId, string $reason): bool;
    
    /**
     * Query memories by surprise/importance thresholds
     */
    public function queryMemoryBySurprise(string $tenantId, array $thresholds, array $filters): array;
    
    /**
     * Get retention policy status for tenant
     */
    public function getRetentionPolicyStatus(string $tenantId): array;
    
    /**
     * Update retention policy configuration
     */
    public function updateRetentionPolicy(string $tenantId, array $policy): bool;
}