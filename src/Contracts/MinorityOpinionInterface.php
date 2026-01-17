<?php
namespace ZionXMemory\Contracts;

/**
 * MinorityOpinionInterface
 * CRITICAL: Preserve and track dissent
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface MinorityOpinionInterface {
    /**
     * Record minority opinion
     */
    public function recordMinorityOpinion(
        string $tenantId,
        string $sessionId,
        array $opinion
    ): void;
    
    /**
     * Track minority accuracy over time
     */
    public function trackAccuracy(
        string $tenantId,
        string $agentId,
        array $outcomes
    ): void;
    
    /**
     * Get "often-right dissenters"
     */
    public function getReliableDissenters(
        string $tenantId,
        array $criteria = []
    ): array;
    
    /**
     * Get preserved dissent for topic
     */
    public function getDissent(
        string $tenantId,
        string $topic
    ): array;
}