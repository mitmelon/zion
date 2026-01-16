<?php
namespace ZionXMemory\Contracts;

/**
 * ChunkingStrategyInterface
 * CHREST-inspired cognitive chunking
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface ChunkingStrategyInterface {
    /**
     * Create cognitive chunks from memory units
     */
    public function createChunks(array $memories, array $criteria): array;
    
    /**
     * Merge related chunks
     */
    public function mergeChunks(array $chunks, float $similarityThreshold): array;
    
    /**
     * Retrieve chunk by pattern
     */
    public function retrieveChunk(string $tenantId, array $pattern): ?array;
    
    /**
     * Update chunk statistics
     */
    public function updateChunkStats(string $tenantId, string $chunkId, array $usage): void;
}