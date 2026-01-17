<?php
namespace ZionXMemory\Contracts;

/**
 * SelfAuditInterface
 * System self-examination capabilities
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface SelfAuditInterface {
    /**
     * Find strongly-believed claims with weak evidence
     */
    public function findWeaklySupported(
        string $tenantId,
        array $thresholds
    ): array;
    
    /**
     * Find contradictions with high confidence
     */
    public function findHighConfidenceConflicts(
        string $tenantId
    ): array;
    
    /**
     * Analyze reasoning quality
     */
    public function analyzeReasoningQuality(
        string $tenantId,
        array $period
    ): array;
    
    /**
     * Get wisdom metrics
     */
    public function getWisdomMetrics(string $tenantId): array;
}