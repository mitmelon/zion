<?php
namespace ZionXMemory\Graph;

/**
 * GraphRelation
 * Represents a confidence-aware relationship between entities
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GraphRelation {
    public string $id;
    public string $tenantId;
    public string $from;
    public string $relation;
    public string $to;
    public array $meta;
    public float $confidence;
    public int $createdAt;
    public array $sources;
    
    // Contradiction tracking
    public bool $hasContradiction;
    public array $contradictingRelations;
    
    public function __construct(
        string $tenantId,
        string $from,
        string $relation,
        string $to,
        array $meta = []
    ) {
        $this->id = $this->generateId($from, $relation, $to);
        $this->tenantId = $tenantId;
        $this->from = $from;
        $this->relation = $relation;
        $this->to = $to;
        $this->meta = $meta;
        $this->confidence = $meta['confidence'] ?? 0.5;
        $this->createdAt = time();
        $this->sources = [];
        $this->hasContradiction = false;
        $this->contradictingRelations = [];
    }
    
    /**
     * Add source claim for this relation
     */
    public function addSource(string $claimId, float $confidence): void {
        $this->sources[] = [
            'claim_id' => $claimId,
            'confidence' => $confidence,
            'added_at' => time()
        ];
        
        // Update confidence
        $this->updateConfidence();
    }
    
    /**
     * Mark as contradicted
     */
    public function markContradicted(array $contradictingRelation): void {
        $this->hasContradiction = true;
        $this->contradictingRelations[] = $contradictingRelation;
    }
    
    /**
     * Update confidence from sources
     */
    private function updateConfidence(): void {
        if (empty($this->sources)) return;
        
        $confidences = array_column($this->sources, 'confidence');
        $this->confidence = array_sum($confidences) / count($confidences);
    }
    
    /**
     * Generate deterministic relation ID
     */
    private function generateId(string $from, string $relation, string $to): string {
        return 'rel_' . hash('sha256', "{$from}:{$relation}:{$to}");
    }
    
    public function toArray(): array {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'from' => $this->from,
            'relation' => $this->relation,
            'to' => $this->to,
            'meta' => $this->meta,
            'confidence' => $this->confidence,
            'created_at' => $this->createdAt,
            'sources' => $this->sources,
            'has_contradiction' => $this->hasContradiction,
            'contradicting_relations' => $this->contradictingRelations
        ];
    }
    
    public static function fromArray(array $data): self {
        $relation = new self(
            $data['tenant_id'],
            $data['from'],
            $data['relation'],
            $data['to'],
            $data['meta'] ?? []
        );
        
        $relation->id = $data['id'];
        $relation->confidence = $data['confidence'];
        $relation->createdAt = $data['created_at'];
        $relation->sources = $data['sources'] ?? [];
        $relation->hasContradiction = $data['has_contradiction'] ?? false;
        $relation->contradictingRelations = $data['contradicting_relations'] ?? [];
        
        return $relation;
    }
}

/**
 * ConflictObject
 * Structured representation of contradictions (NOT text)
 */
class ConflictObject {
    public string $id;
    public string $tenantId;
    public string $entityId;
    public string $conflictType;
    public array $conflictingRelations;
    public float $severityScore;
    public array $metadata;
    public int $detectedAt;
    
    public function __construct(
        string $tenantId,
        string $entityId,
        string $conflictType
    ) {
        $this->id = 'conflict_' . bin2hex(random_bytes(8));
        $this->tenantId = $tenantId;
        $this->entityId = $entityId;
        $this->conflictType = $conflictType;
        $this->conflictingRelations = [];
        $this->severityScore = 0.0;
        $this->metadata = [];
        $this->detectedAt = time();
    }
    
    /**
     * Add conflicting relation
     */
    public function addConflictingRelation(array $relation): void {
        $this->conflictingRelations[] = $relation;
        $this->calculateSeverity();
    }
    
    /**
     * Calculate conflict severity
     * Higher when both sides have high confidence
     */
    private function calculateSeverity(): void {
        if (count($this->conflictingRelations) < 2) {
            $this->severityScore = 0.0;
            return;
        }
        
        $confidences = array_column($this->conflictingRelations, 'confidence');
        
        // High severity when both have high confidence
        $minConfidence = min($confidences);
        $avgConfidence = array_sum($confidences) / count($confidences);
        
        $this->severityScore = $minConfidence * $avgConfidence;
    }
    
    public function toArray(): array {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'entity_id' => $this->entityId,
            'conflict_type' => $this->conflictType,
            'conflicting_relations' => $this->conflictingRelations,
            'severity_score' => $this->severityScore,
            'metadata' => $this->metadata,
            'detected_at' => $this->detectedAt
        ];
    }
}

/**
 * DecisionLineage
 * Tracks complete decision provenance
 */
class DecisionLineage {
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