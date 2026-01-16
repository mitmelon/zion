<?php
namespace ZionXMemory\Graph;

/**
 * BeliefNode - Represents an entity or concept in the knowledge graph
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class BeliefNode {
    public string $id;
    public string $type;
    public array $properties;
    public int $timestamp;
    public array $metadata;
    
    public function __construct(string $id, string $type, array $properties, int $timestamp) {
        $this->id = $id;
        $this->type = $type;
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
            'type' => $this->type,
            'properties' => $this->properties,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata
        ];
    }
    
    public static function fromArray(array $data): self {
        $node = new self(
            $data['id'],
            $data['type'],
            $data['properties'],
            $data['timestamp']
        );
        $node->metadata = $data['metadata'] ?? $node->metadata;
        return $node;
    }
}