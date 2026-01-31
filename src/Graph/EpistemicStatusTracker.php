<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;
use ZionXMemory\Contracts\EpistemicStatusInterface;


/**
 * EpistemicStatusTracker
 * CRITICAL: Distinguishes facts from assumptions
 * Enables "Are we reasoning from facts or assumptions?" queries
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class EpistemicStatusTracker implements EpistemicStatusInterface {
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
     * Set epistemic status for claim
     */
    public function setStatus(
        string $tenantId,
        string $claimId,
        string $status,
        array $justification
    ): void {
        $this->validateStatus($status);
        
        $key = $this->buildStatusKey($tenantId, $claimId);
        
        // Get current status
        $current = $this->storage->read($key);
        $oldStatus = $current['status'] ?? self::STATUS_HYPOTHESIS;
        
        // Create status record
        $statusRecord = [
            'claim_id' => $claimId,
            'tenant_id' => $tenantId,
            'status' => $status,
            'previous_status' => $oldStatus,
            'justification' => $justification,
            'set_at' => time(),
            'set_by' => $justification['agent_id'] ?? 'system'
        ];
        
        $this->storage->write($key, $statusRecord, [
            'tenant' => $tenantId,
            'type' => 'epistemic_status'
        ]);
        
        // Track transition
        if ($oldStatus !== $status) {
            $this->trackTransition(
                $tenantId,
                $claimId,
                $oldStatus,
                $status,
                $justification['reason'] ?? 'status_update'
            );
        }
        
        // Update index
        $this->updateStatusIndex($tenantId, $status, $claimId);
        
        $this->audit->log($tenantId, 'epistemic_status_set', [
            'claim_id' => $claimId,
            'status' => $status,
            'from' => $oldStatus
        ], ['timestamp' => time()]);
    }
    
    /**
     * Get claims by epistemic status
     */
    public function getClaimsByStatus(string $tenantId, string $status): array {
        $this->validateStatus($status);
        
        $indexKey = "epistemic_index:{$tenantId}:{$status}";
        $claimIds = $this->storage->getSetMembers($indexKey);
        
        $claims = [];
        foreach ($claimIds as $claimId) {
            $statusRecord = $this->getStatus($tenantId, $claimId);
            if ($statusRecord) {
                $claims[] = $statusRecord;
            }
        }
        
        return $claims;
    }
    
    /**
     * Track status transitions
     */
    public function trackTransition(
        string $tenantId,
        string $claimId,
        string $fromStatus,
        string $toStatus,
        string $reason
    ): void {
        $key = "epistemic_transitions:{$tenantId}:{$claimId}";
        $transitions = $this->storage->read($key) ?? [];
        
        $transitions[] = [
            'from' => $fromStatus,
            'to' => $toStatus,
            'reason' => $reason,
            'timestamp' => time()
        ];
        
        $this->storage->write($key, $transitions, ['tenant' => $tenantId]);
    }
    
    /**
     * CRITICAL: Query reasoning basis
     * Answers: "Are we reasoning from facts or assumptions?"
     */
    public function getReasoningBasis(string $tenantId, array $claimIds): array {
        $basis = [
            'facts' => [],         // Evidence-based
            'assumptions' => [],   // Assumed
            'hypotheses' => [],    // Unconfirmed
            'decisions' => [],     // Derived decisions
            'rejected' => []       // Known false
        ];
        
        $totalConfidence = 0;
        $confidenceByType = [];
        
        foreach ($claimIds as $claimId) {
            $statusRecord = $this->getStatus($tenantId, $claimId);
            if (!$statusRecord) continue;
            
            $status = $statusRecord['status'];
            $confidence = $statusRecord['justification']['confidence'] ?? 0.5;
            
            switch ($status) {
                case self::STATUS_EVIDENCE:
                case self::STATUS_CONFIRMED:
                    $basis['facts'][] = $claimId;
                    $confidenceByType['facts'] = ($confidenceByType['facts'] ?? 0) + $confidence;
                    break;
                    
                case self::STATUS_ASSUMPTION:
                    $basis['assumptions'][] = $claimId;
                    $confidenceByType['assumptions'] = ($confidenceByType['assumptions'] ?? 0) + $confidence;
                    break;
                    
                case self::STATUS_HYPOTHESIS:
                case self::STATUS_CONTESTED:
                    $basis['hypotheses'][] = $claimId;
                    $confidenceByType['hypotheses'] = ($confidenceByType['hypotheses'] ?? 0) + $confidence;
                    break;
                    
                case self::STATUS_DECISION:
                    $basis['decisions'][] = $claimId;
                    $confidenceByType['decisions'] = ($confidenceByType['decisions'] ?? 0) + $confidence;
                    break;
                    
                case self::STATUS_REJECTED:
                    $basis['rejected'][] = $claimId;
                    break;
            }
            
            $totalConfidence += $confidence;
        }
        
        // Calculate reasoning quality
        $factRatio = count($basis['facts']) / max(1, count($claimIds));
        $assumptionRatio = count($basis['assumptions']) / max(1, count($claimIds));
        
        return [
            'basis' => $basis,
            'fact_ratio' => $factRatio,
            'assumption_ratio' => $assumptionRatio,
            'total_claims' => count($claimIds),
            'avg_confidence' => $totalConfidence / max(1, count($claimIds)),
            'confidence_by_type' => $confidenceByType,
            'reasoning_quality' => $this->assessReasoningQuality($factRatio, $assumptionRatio)
        ];
    }
    
    /**
     * Get status for claim
     */
    private function getStatus(string $tenantId, string $claimId): ?array {
        $key = $this->buildStatusKey($tenantId, $claimId);
        return $this->storage->read($key);
    }
    
    /**
     * Assess reasoning quality
     */
    private function assessReasoningQuality(float $factRatio, float $assumptionRatio): string {
        if ($factRatio >= 0.7) {
            return 'strong'; // Mostly facts
        } elseif ($assumptionRatio >= 0.7) {
            return 'weak'; // Mostly assumptions
        } elseif ($factRatio >= 0.4) {
            return 'moderate'; // Mixed
        }
        
        return 'speculative'; // Mostly hypotheses
    }
    
    /**
     * Validate status value
     */
    private function validateStatus(string $status): void {
        $valid = [
            self::STATUS_HYPOTHESIS,
            self::STATUS_EVIDENCE,
            self::STATUS_ASSUMPTION,
            self::STATUS_DECISION,
            self::STATUS_REJECTED,
            self::STATUS_CONFIRMED,
            self::STATUS_CONTESTED
        ];
        
        if (!in_array($status, $valid)) {
            throw new \InvalidArgumentException("Invalid epistemic status: {$status}");
        }
    }
    
    /**
     * Update status index for efficient querying
     */
    private function updateStatusIndex(string $tenantId, string $status, string $claimId): void {
        $indexKey = "epistemic_index:{$tenantId}:{$status}";
        $this->storage->addToSet($indexKey, $claimId, ['tenant' => $tenantId]);
    }
    
    private function buildStatusKey(string $tenantId, string $claimId): string {
        return "epistemic_status:{$tenantId}:{$claimId}";
    }
}