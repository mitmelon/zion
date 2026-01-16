<?php
namespace ZionXMemory\Graph;

/**
 * RelationEdge - Represents a relationship between two nodes
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class RelationEdge {
    public string $id;
    public string $fromId;
    public string $toId;
    public string $relationType;
    public array $properties;
    public int $timestamp;
    public array $metadata;
    
    public function __construct(
        string $id,
        string $fromId,
        string $toId,
        string $relationType,
        array $properties,
        int $timestamp
    ) {
        $this->id = $id;
        $this->fromId = $fromId;
        $this->toId = $toId;
        $this->relationType = $relationType;
        $this->properties = $properties;
        $this->timestamp = $timestamp;
        $this->metadata = [
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'version' => 1
        ];
    }
    
    public function updateProperties(array $newProperties, string $reason): void {
        $this->properties = array_merge($this->properties, $newProperties);
        $this->metadata['updated_at'] = time();
        $this->metadata['version']++;
        $this->metadata['last_update_reason'] = $reason;
    }
    
    public function toArray(): array {
        return [
            'id' => $this->id,
            'from_id' => $this->fromId,
            'to_id' => $this->toId,
            'relation_type' => $this->relationType,
            'properties' => $this->properties,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata
        ];
    }
    
    public static function fromArray(array $data): self {
        $edge = new self(
            $data['id'],
            $data['from_id'],
            $data['to_id'],
            $data['relation_type'],
            $data['properties'],
            $data['timestamp']
        );
        $edge->metadata = $data['metadata'] ?? $edge->metadata;
        return $edge;
    }
}