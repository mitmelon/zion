<?php
namespace ZionXMemory\Contracts;

/**
 * GraphIngestorInterface
 * Transforms memory claims into knowledge graph
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface GraphIngestorInterface {
    /**
     * Ingest claims from a session into graph
     * IDEMPOTENT: Can be called multiple times safely
     */
    public function ingestFromSession(string $tenantId, string $sessionId): array;
    
    /**
     * Ingest single claim into graph
     */
    public function ingestClaim(string $tenantId, array $claim, array $context, ?array $preExtracted = null): array;
    
    /**
     * Get ingestion statistics
     */
    public function getIngestionStats(string $tenantId): array;
}
