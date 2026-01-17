<?php
namespace ZionXMemory\Graph;

/**
 * DecisionLineage
 * Tracks complete decision provenance
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class DecisionLineage  {
    public string $decisionId;
    public string $tenantId;
    public string $decision;
    public array $claimsUsed;
    public array $claimsRejected;
    public array $conflictsUnresolved;
    public float $confidenceScore;
    public array $reasoning;
    public int $timestamp;
    
    public function __construct(
        string $decisionId,
        string $tenantId,
        string $decision
    ) {
        $this->decisionId = $decisionId;
        $this->tenantId = $tenantId;
        $this->decision = $decision;
        $this->claimsUsed = [];
        $this->claimsRejected = [];
        $this->conflictsUnresolved = [];
        $this->confidenceScore = 0.0;
        $this->reasoning = [];
        $this->timestamp = time();
    }
    
    /**
     * Add used claim
     */
    public function addUsedClaim(string $claimId, float $weight): void {
        $this->claimsUsed[] = [
            'claim_id' => $claimId,
            'weight' => $weight
        ];
        $this->recalculateConfidence();
    }
    
    /**
     * Add rejected claim
     */
    public function addRejectedClaim(string $claimId, string $reason): void {
        $this->claimsRejected[] = [
            'claim_id' => $claimId,
            'reason' => $reason
        ];
    }
    
    /**
     * Add unresolved conflict
     */
    public function addUnresolvedConflict(array $conflict): void {
        $this->conflictsUnresolved[] = $conflict;
    }
    
    /**
     * Recalculate decision confidence
     */
    private function recalculateConfidence(): void {
        if (empty($this->claimsUsed)) {
            $this->confidenceScore = 0.0;
            return;
        }
        
        $totalWeight = array_sum(array_column($this->claimsUsed, 'weight'));
        $this->confidenceScore = $totalWeight / count($this->claimsUsed);
    }
    
    public function toArray(): array {
        return [
            'decision_id' => $this->decisionId,
            'tenant_id' => $this->tenantId,
            'decision' => $this->decision,
            'claims_used' => $this->claimsUsed,
            'claims_rejected' => $this->claimsRejected,
            'conflicts_unresolved' => $this->conflictsUnresolved,
            'confidence_score' => $this->confidenceScore,
            'reasoning' => $this->reasoning,
            'timestamp' => $this->timestamp
        ];
    }
}