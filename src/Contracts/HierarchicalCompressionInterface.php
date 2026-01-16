<?php
namespace ZionXMemory\Contracts;

/**
 * HierarchicalCompressionInterface
 * Multi-level memory compression with surprise-aware preservation
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface HierarchicalCompressionInterface {
    /**
     * Compress memory unit to target level
     */
    public function compress(array $memoryUnit, int $targetLevel, array $preservationCriteria): array;
    
    /**
     * Create hierarchical summary
     */
    public function createHierarchicalSummary(array $memories, array $surpriseScores): array;
    
    /**
     * Decompress memory unit
     */
    public function decompress(string $tenantId, string $memoryUnitId): array;
    
    /**
     * Get compression ratio
     */
    public function getCompressionRatio(string $tenantId): array;
}