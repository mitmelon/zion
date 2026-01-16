<?php
namespace ZionXMemory\Contracts;

/**
 * SparseAttentionInterface
 * Ultra-long context handling with hierarchical sparse attention
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

interface SparseAttentionInterface {
    /**
     * Build sparse attention index
     */
    public function buildAttentionIndex(array $memories): array;
    
    /**
     * Query with sparse attention
     */
    public function queryWithSparseAttention(string $tenantId, array $query, int $maxTokens): array;
    
    /**
     * Update attention patterns
     */
    public function updateAttentionPatterns(string $tenantId, array $accessPatterns): void;
    
    /**
     * Get attention statistics
     */
    public function getAttentionStats(string $tenantId): array;
}
