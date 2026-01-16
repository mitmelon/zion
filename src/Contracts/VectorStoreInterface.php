<?php
namespace ZionXMemory\Contracts;

/**
 * VectorStoreInterface
 * Extended storage for embeddings and similarity search
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

interface VectorStoreInterface {
    /**
     * Store vector embedding
     */
    public function storeVector(string $id, array $vector, array $metadata): bool;
    
    /**
     * Search similar vectors
     */
    public function searchSimilar(array $queryVector, int $k, array $filters): array;
    
    /**
     * Update vector
     */
    public function updateVector(string $id, array $vector): bool;
    
    /**
     * Get vector by ID
     */
    public function getVector(string $id): ?array;
    
    /**
     * Batch vector operations
     */
    public function batchStore(array $vectors): bool;
}