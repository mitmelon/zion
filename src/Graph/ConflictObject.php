<?php
namespace ZionXMemory\Graph;

/**
 * ConflictObject
 * Structured representation of contradictions (NOT text)
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
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