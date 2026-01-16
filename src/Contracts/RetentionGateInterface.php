<?php
namespace ZionXMemory\Contracts;

/**
 * RetentionGateInterface
 * Implements controlled forgetting and memory aging
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface RetentionGateInterface {
    /**
     * Evaluate memory unit for retention
     */
    public function evaluateRetention(string $tenantId, string $memoryUnitId): array;
    
    /**
     * Apply decay to memory importance
     */
    public function applyDecay(string $tenantId, array $memoryUnits, float $decayRate): array;
    
    /**
     * Check if memory should be compressed
     */
    public function shouldCompress(array $memoryUnit, array $policy): bool;
    
    /**
     * Check if memory should be promoted
     */
    public function shouldPromote(array $memoryUnit, array $policy): bool;
    
    /**
     * Get forgetting candidates
     */
    public function getForgettingCandidates(string $tenantId, array $criteria): array;
}