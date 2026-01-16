<?php
namespace ZionXMemory\Contracts;

/**
 * MemoryPriorityInterface
 * ATLAS-inspired priority and importance management
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface MemoryPriorityInterface {
    /**
     * Calculate memory priority for retrieval
     */
    public function calculatePriority(array $memoryUnit, array $queryContext): float;
    
    /**
     * Rerank memories by importance
     */
    public function rerankByImportance(array $memories, array $criteria): array;
    
    /**
     * Get top-k most important memories
     */
    public function getTopKImportant(string $tenantId, int $k, array $filters): array;
    
    /**
     * Update importance scores based on usage
     */
    public function updateImportanceFromUsage(string $tenantId, array $accessLog): void;
}