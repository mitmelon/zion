<?php
namespace ZionXMemory\Graph;

/**
 * GraphEntity
 * Represents a normalized concept in the knowledge graph
 * DERIVED from memory claims, not raw conversations
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GraphEntity {
    public string $id;
    public string $tenantId;
    public string $type;
    public array $attributes;
    public array $metadata;
    public int $createdAt;
    public int $updatedAt;
    
    // Epistemic properties
    public string $epistemicStatus;
    public array $sources; // Memory claim IDs
    public float $aggregateConfidence;
    
    public function __construct(
        string $id,
        string $tenantId,
        string $type,
        array $attributes = []
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->type = $type;
        $this->attributes = $attributes;
        $this->metadata = [];
        $this->createdAt = time();
        $this->updatedAt = time();
        $this->epistemicStatus = 'hypothesis';
        $this->sources = [];
        $this->aggregateConfidence = 0.5;
    }
    
    /**
     * Add source claim
     */
    public function addSource(string $claimId, float $confidence): void {
        $this->sources[] = [
            'claim_id' => $claimId,
            'confidence' => $confidence,
            'added_at' => time()
        ];
        
        // Recalculate aggregate confidence
        $this->recalculateConfidence();
    }
    
    /**
     * Update epistemic status
     */
    public function updateStatus(string $status, string $reason): void {
        $this->metadata['status_history'][] = [
            'from' => $this->epistemicStatus,
            'to' => $status,
            'reason' => $reason,
            'timestamp' => time()
        ];
        
        $this->epistemicStatus = $status;
        $this->updatedAt = time();
    }
    
    /**
     * Recalculate aggregate confidence from sources
     */
    private function recalculateConfidence(): void {
        if (empty($this->sources)) {
            $this->aggregateConfidence = 0.5;
            return;
        }
        
        // Weighted average with recency bias
        $totalWeight = 0;
        $weightedSum = 0;
        $now = time();
        
        foreach ($this->sources as $source) {
            // More recent sources have higher weight
            $age = $now - $source['added_at'];
            $recencyWeight = exp(-$age / (30 * 86400)); // 30-day half-life
            
            $weight = $recencyWeight * $source['confidence'];
            $weightedSum += $weight * $source['confidence'];
            $totalWeight += $weight;
        }
        
        $this->aggregateConfidence = $totalWeight > 0 ? $weightedSum / $totalWeight : 0.5;
    }
    
    public function toArray(): array {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'type' => $this->type,
            'attributes' => $this->attributes,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'epistemic_status' => $this->epistemicStatus,
            'sources' => $this->sources,
            'aggregate_confidence' => $this->aggregateConfidence
        ];
    }
    
    public static function fromArray(array $data): self {
        $entity = new self(
            $data['id'],
            $data['tenant_id'],
            $data['type'],
            $data['attributes'] ?? []
        );
        
        $entity->metadata = $data['metadata'] ?? [];
        $entity->createdAt = $data['created_at'];
        $entity->updatedAt = $data['updated_at'];
        $entity->epistemicStatus = $data['epistemic_status'] ?? 'hypothesis';
        $entity->sources = $data['sources'] ?? [];
        $entity->aggregateConfidence = $data['aggregate_confidence'] ?? 0.5;
        
        return $entity;
    }
}