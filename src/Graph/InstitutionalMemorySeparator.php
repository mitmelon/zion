<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;
use ZionXMemory\Contracts\InstitutionalMemoryInterface;
use ZionXMemory\Contracts\EpistemicStatusInterface;

/**
 * InstitutionalMemorySeparator
 * CRITICAL: Separates session memory from institutional wisdom
 * Only what survives debate feeds the graph
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class InstitutionalMemorySeparator implements InstitutionalMemoryInterface {
    private StorageAdapterInterface $storage;
    private AuditInterface $audit;
    private EpistemicStatusInterface $epistemic;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AuditInterface $audit,
        EpistemicStatusInterface $epistemic
    ) {
        $this->storage = $storage;
        $this->audit = $audit;
        $this->epistemic = $epistemic;
    }
    
    /**
     * Promote session memory to institutional
     * Only knowledge that survived deliberation
     */
    public function promoteToInstitutional(
        string $tenantId,
        string $sessionId,
        array $criteria
    ): array {
        $minConfidence = $criteria['min_confidence'] ?? 0.7;
        $minAgreement = $criteria['min_agreement'] ?? 0.6;
        $requireEvidence = $criteria['require_evidence'] ?? true;
        
        // Get session claims
        $sessionClaims = $this->getSession($tenantId, $sessionId);
        
        $promoted = [];
        $rejected = [];
        
        foreach ($sessionClaims as $claim) {
            $eligible = $this->checkPromotionEligibilityForClaim($claim, [
                'min_confidence' => $minConfidence,
                'min_agreement' => $minAgreement,
                'require_evidence' => $requireEvidence
            ]);
            
            if ($eligible['eligible']) {
                // Promote to institutional
                $this->promoteClaimToInstitutional($tenantId, $claim);
                $promoted[] = $claim['id'];
                
                // Update epistemic status
                $this->epistemic->setStatus(
                    $tenantId,
                    $claim['id'],
                    EpistemicStatusInterface::STATUS_CONFIRMED,
                    ['reason' => 'promoted_from_session', 'session_id' => $sessionId]
                );
            } else {
                $rejected[] = [
                    'claim_id' => $claim['id'],
                    'reasons' => $eligible['reasons']
                ];
            }
        }
        
        $this->audit->log($tenantId, 'institutional_promotion', [
            'session_id' => $sessionId,
            'promoted' => count($promoted),
            'rejected' => count($rejected)
        ], ['timestamp' => time()]);
        
        return [
            'promoted' => $promoted,
            'rejected' => $rejected,
            'promotion_rate' => count($promoted) / max(1, count($sessionClaims))
        ];
    }
    
    /**
     * Get institutional memory
     */
    public function getInstitutional(string $tenantId, array $filters = []): array {
        $pattern = "institutional:{$tenantId}:*";
        $institutional = $this->storage->query(['pattern' => $pattern]);
        
        // Apply filters
        if (isset($filters['min_confidence'])) {
            $institutional = array_filter($institutional, fn($c) => 
                $c['confidence']['mean'] >= $filters['min_confidence']
            );
        }
        
        if (isset($filters['topic'])) {
            $institutional = array_filter($institutional, fn($c) => 
                $c['topic'] === $filters['topic']
            );
        }
        
        return array_values($institutional);
    }
    
    /**
     * Get session memory
     */
    public function getSession(string $tenantId, string $sessionId): array {
        $key = "session:{$tenantId}:{$sessionId}:claims";
        return $this->storage->read($key) ?? [];
    }
    
    /**
     * Check promotion eligibility for session
     */
    public function checkPromotionEligibility(
        string $tenantId,
        string $sessionId
    ): array {
        $claims = $this->getSession($tenantId, $sessionId);
        
        $eligibility = [
            'total_claims' => count($claims),
            'eligible_claims' => 0,
            'ineligible_claims' => 0,
            'reasons' => []
        ];
        
        foreach ($claims as $claim) {
            $check = $this->checkPromotionEligibilityForClaim($claim, []);
            
            if ($check['eligible']) {
                $eligibility['eligible_claims']++;
            } else {
                $eligibility['ineligible_claims']++;
                $eligibility['reasons'] = array_merge($eligibility['reasons'], $check['reasons']);
            }
        }
        
        return $eligibility;
    }
    
    /**
     * Check if single claim is eligible for promotion
     */
    private function checkPromotionEligibilityForClaim(array $claim, array $criteria): array {
        $reasons = [];
        $eligible = true;
        
        $minConfidence = $criteria['min_confidence'] ?? 0.7;
        $requireEvidence = $criteria['require_evidence'] ?? true;
        
        // Check confidence
        $confidence = $claim['confidence']['mean'] ?? 0;
        if ($confidence < $minConfidence) {
            $eligible = false;
            $reasons[] = "Low confidence: {$confidence} < {$minConfidence}";
        }
        
        // Check evidence
        if ($requireEvidence) {
            $evidenceCount = count($claim['evidence'] ?? []);
            if ($evidenceCount === 0) {
                $eligible = false;
                $reasons[] = "No supporting evidence";
            }
        }
        
        // Check if contested
        if (isset($claim['is_contested']) && $claim['is_contested']) {
            $eligible = false;
            $reasons[] = "Claim is contested";
        }
        
        return [
            'eligible' => $eligible,
            'reasons' => $reasons
        ];
    }
    
    /**
     * Promote claim to institutional memory
     */
    private function promoteClaimToInstitutional(string $tenantId, array $claim): void {
        $key = "institutional:{$tenantId}:{$claim['id']}";
        
        $institutional = $claim;
        $institutional['promoted_at'] = time();
        $institutional['institutional'] = true;
        
        $this->storage->write($key, $institutional, [
            'tenant' => $tenantId,
            'type' => 'institutional_memory'
        ]);
    }
}