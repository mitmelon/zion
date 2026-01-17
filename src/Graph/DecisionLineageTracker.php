<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;
use ZionXMemory\Contracts\DecisionLineageInterface;

/**
 * DecisionLineageTracker
 * Tracks complete decision provenance for report generation
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class DecisionLineageTracker implements DecisionLineageInterface {
    private StorageAdapterInterface $storage;
    private AuditInterface $audit;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AuditInterface $audit
    ) {
        $this->storage = $storage;
        $this->audit = $audit;
    }
    
    /**
     * Record decision with full lineage
     */
    public function recordDecision(
        string $tenantId,
        string $decisionId,
        array $decision
    ): void {
        $lineage = new DecisionLineage($decisionId, $tenantId, $decision['decision'] ?? '');
        
        // Add used claims
        foreach ($decision['claims_used'] ?? [] as $claim) {
            $lineage->addUsedClaim($claim['claim_id'], $claim['weight'] ?? 1.0);
        }
        
        // Add rejected claims
        foreach ($decision['claims_rejected'] ?? [] as $claim) {
            $lineage->addRejectedClaim($claim['claim_id'], $claim['reason'] ?? 'unknown');
        }
        
        // Add unresolved conflicts
        foreach ($decision['conflicts_unresolved'] ?? [] as $conflict) {
            $lineage->addUnresolvedConflict($conflict);
        }
        
        // Set reasoning
        $lineage->reasoning = $decision['reasoning'] ?? [];
        
        $key = $this->buildLineageKey($tenantId, $decisionId);
        $this->storage->write($key, $lineage->toArray(), [
            'tenant' => $tenantId,
            'type' => 'decision_lineage'
        ]);
        
        // Index by used claims for downstream tracking
        foreach ($decision['claims_used'] ?? [] as $claim) {
            $this->indexDecisionByClaim($tenantId, $claim['claim_id'], $decisionId);
        }
        
        $this->audit->log($tenantId, 'decision_recorded', [
            'decision_id' => $decisionId,
            'claims_used' => count($decision['claims_used'] ?? []),
            'claims_rejected' => count($decision['claims_rejected'] ?? [])
        ], ['timestamp' => time()]);
    }
    
    /**
     * Get decision lineage
     */
    public function getDecisionLineage(string $tenantId, string $decisionId): array {
        $key = $this->buildLineageKey($tenantId, $decisionId);
        return $this->storage->read($key) ?? [];
    }
    
    /**
     * Get all decisions depending on a claim
     */
    public function getDownstreamDecisions(string $tenantId, string $claimId): array {
        $indexKey = "decision_index:{$tenantId}:claim:{$claimId}";
        $decisionIds = $this->storage->read($indexKey) ?? [];
        
        $decisions = [];
        foreach ($decisionIds as $decisionId) {
            $lineage = $this->getDecisionLineage($tenantId, $decisionId);
            if ($lineage) {
                $decisions[] = $lineage;
            }
        }
        
        return $decisions;
    }
    
    /**
     * Generate decision report
     */
    public function generateDecisionReport(string $tenantId, string $decisionId): array {
        $lineage = $this->getDecisionLineage($tenantId, $decisionId);
        
        if (empty($lineage)) {
            return ['error' => 'Decision not found'];
        }
        
        $report = [
            'decision_id' => $decisionId,
            'decision' => $lineage['decision'],
            'timestamp' => $lineage['timestamp'],
            'confidence' => $lineage['confidence_score'],
            'sections' => []
        ];
        
        // Section 1: Executive Summary
        $report['sections']['executive_summary'] = [
            'decision' => $lineage['decision'],
            'confidence' => $lineage['confidence_score'],
            'claims_considered' => count($lineage['claims_used']) + count($lineage['claims_rejected'])
        ];
        
        // Section 2: Claims Used
        $report['sections']['claims_used'] = $lineage['claims_used'];
        
        // Section 3: Claims Rejected
        $report['sections']['claims_rejected'] = $lineage['claims_rejected'];
        
        // Section 4: Unresolved Conflicts
        $report['sections']['conflicts'] = $lineage['conflicts_unresolved'];
        
        // Section 5: Reasoning Chain
        $report['sections']['reasoning'] = $lineage['reasoning'];
        
        // Section 6: Downstream Impact
        $report['sections']['downstream_impact'] = $this->analyzeDownstreamImpact($tenantId, $decisionId);
        
        return $report;
    }
    
    /**
     * Analyze downstream impact of decision
     */
    private function analyzeDownstreamImpact(string $tenantId, string $decisionId): array {
        // Find decisions that depend on this one
        $pattern = "decision_lineage:{$tenantId}:*";
        $allDecisions = $this->storage->query(['pattern' => $pattern]);
        
        $dependentDecisions = [];
        foreach ($allDecisions as $decision) {
            $usedClaims = array_column($decision['claims_used'] ?? [], 'claim_id');
            
            // If this decision ID appears as a used claim
            if (in_array($decisionId, $usedClaims)) {
                $dependentDecisions[] = $decision['decision_id'];
            }
        }
        
        return [
            'dependent_decisions' => $dependentDecisions,
            'impact_count' => count($dependentDecisions)
        ];
    }
    
    /**
     * Index decision by claim for downstream tracking
     */
    private function indexDecisionByClaim(string $tenantId, string $claimId, string $decisionId): void {
        $indexKey = "decision_index:{$tenantId}:claim:{$claimId}";
        $index = $this->storage->read($indexKey) ?? [];
        
        if (!in_array($decisionId, $index)) {
            $index[] = $decisionId;
            $this->storage->write($indexKey, $index, ['tenant' => $tenantId]);
        }
    }
    
    private function buildLineageKey(string $tenantId, string $decisionId): string {
        return "decision_lineage:{$tenantId}:{$decisionId}";
    }
}