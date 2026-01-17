<?php
namespace ZionXMemory\Contracts;

/**
 * InstitutionalMemoryInterface
 * Separates session memory from institutional wisdom
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface InstitutionalMemoryInterface {
    /**
     * Promote session memory to institutional
     */
    public function promoteToInstitutional(
        string $tenantId,
        string $sessionId,
        array $criteria
    ): array;
    
    /**
     * Get institutional memory
     */
    public function getInstitutional(
        string $tenantId,
        array $filters = []
    ): array;
    
    /**
     * Get session memory
     */
    public function getSession(
        string $tenantId,
        string $sessionId
    ): array;
    
    /**
     * Check promotion eligibility
     */
    public function checkPromotionEligibility(
        string $tenantId,
        string $sessionId
    ): array;
}