<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\GraphConsistencyCheckerInterface;
use ZionXMemory\Contracts\GraphStoreInterface;

/**
 * GraphConsistencyChecker
 * Detects contradictions and conflicts in knowledge graph
 * Returns STRUCTURED conflict objects (NOT text)
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GraphConsistencyChecker implements GraphConsistencyCheckerInterface {
    private GraphStoreInterface $graphStore;
    private StorageAdapterInterface $storage;
    
    public function __construct(
        GraphStoreInterface $graphStore,
        StorageAdapterInterface $storage
    ) {
        $this->graphStore = $graphStore;
        $this->storage = $storage;
    }
    
    /**
     * Detect conflicts for specific entity
     * Returns structured ConflictObject instances
     */
    public function detectConflicts(string $tenantId, string $entityId): array {
        $relations = $this->graphStore->getRelations($tenantId, $entityId);
        
        if (empty($relations)) {
            return [];
        }
        
        $conflicts = [];
        
        // Group relations by type
        $relationsByType = [];
        foreach ($relations as $relation) {
            $type = $relation['relation'];
            $relationsByType[$type][] = $relation;
        }
        
        // Check for contradictions within each relation type
        foreach ($relationsByType as $relationType => $rels) {
            $typeConflicts = $this->findContradictionsInRelationType(
                $tenantId,
                $entityId,
                $relationType,
                $rels
            );
            
            $conflicts = array_merge($conflicts, $typeConflicts);
        }
        
        return $conflicts;
    }
    
    /**
     * Check consistency across entire graph
     */
    public function checkConsistency(string $tenantId): array {
        $pattern = "graph:entity:{$tenantId}:*";
        $entities = $this->storage->query(['pattern' => $pattern]);
        
        $allConflicts = [];
        $checkedEntities = 0;
        
        foreach ($entities as $entity) {
            $entityId = $entity['id'];
            $conflicts = $this->detectConflicts($tenantId, $entityId);
            
            if (!empty($conflicts)) {
                $allConflicts[$entityId] = $conflicts;
            }
            
            $checkedEntities++;
        }
        
        return [
            'checked_entities' => $checkedEntities,
            'entities_with_conflicts' => count($allConflicts),
            'total_conflicts' => array_sum(array_map('count', $allConflicts)),
            'conflicts' => $allConflicts
        ];
    }
    
    /**
     * Get contradiction summary
     */
    public function getContradictionSummary(string $tenantId): array {
        $consistency = $this->checkConsistency($tenantId);
        
        $highSeverity = [];
        $mediumSeverity = [];
        $lowSeverity = [];
        
        foreach ($consistency['conflicts'] as $entityId => $conflicts) {
            foreach ($conflicts as $conflict) {
                $severity = $conflict['severity_score'];
                
                if ($severity >= 0.7) {
                    $highSeverity[] = $conflict;
                } elseif ($severity >= 0.4) {
                    $mediumSeverity[] = $conflict;
                } else {
                    $lowSeverity[] = $conflict;
                }
            }
        }
        
        return [
            'total_conflicts' => $consistency['total_conflicts'],
            'high_severity' => count($highSeverity),
            'medium_severity' => count($mediumSeverity),
            'low_severity' => count($lowSeverity),
            'details' => [
                'high' => $highSeverity,
                'medium' => $mediumSeverity,
                'low' => $lowSeverity
            ]
        ];
    }
    
    /**
     * Validate specific relation for consistency
     */
    public function validateRelation(
        string $tenantId,
        string $from,
        string $relation,
        string $to
    ): array {
        // Get all relations of this type from the same entity
        $existingRelations = $this->graphStore->getRelations($tenantId, $from);
        
        $sameTypeRelations = array_filter($existingRelations, function($rel) use ($relation) {
            return $rel['relation'] === $relation;
        });
        
        $conflicts = [];
        
        foreach ($sameTypeRelations as $existing) {
            if ($existing['to'] !== $to) {
                // Different target for same relation type = potential conflict
                $conflict = new ConflictObject($tenantId, $from, 'relation_conflict');
                $conflict->addConflictingRelation([
                    'relation' => $relation,
                    'to' => $existing['to'],
                    'confidence' => $existing['confidence']
                ]);
                $conflict->addConflictingRelation([
                    'relation' => $relation,
                    'to' => $to,
                    'confidence' => 0.5 // Proposed relation
                ]);
                
                $conflicts[] = $conflict->toArray();
            }
        }
        
        return [
            'valid' => empty($conflicts),
            'conflicts' => $conflicts
        ];
    }
    
    /**
     * Find contradictions within a relation type
     */
    private function findContradictionsInRelationType(
        string $tenantId,
        string $entityId,
        string $relationType,
        array $relations
    ): array {
        $conflicts = [];
        
        // Check for multiple high-confidence targets
        if (count($relations) > 1) {
            $highConfidenceRels = array_filter($relations, fn($r) => $r['confidence'] >= 0.6);
            
            if (count($highConfidenceRels) > 1) {
                // Multiple high-confidence relations of same type = contradiction
                $conflict = new ConflictObject($tenantId, $entityId, 'multiple_high_confidence');
                $conflict->metadata['relation_type'] = $relationType;
                
                foreach ($highConfidenceRels as $rel) {
                    $conflict->addConflictingRelation($rel);
                }
                
                $conflicts[] = $conflict->toArray();
            }
        }
        
        // Check for semantic contradictions
        $semanticConflicts = $this->detectSemanticContradictions($relations);
        foreach ($semanticConflicts as $sc) {
            $conflict = new ConflictObject($tenantId, $entityId, 'semantic_contradiction');
            $conflict->metadata['relation_type'] = $relationType;
            
            foreach ($sc as $rel) {
                $conflict->addConflictingRelation($rel);
            }
            
            $conflicts[] = $conflict->toArray();
        }
        
        return $conflicts;
    }
    
    /**
     * Detect semantic contradictions
     */
    private function detectSemanticContradictions(array $relations): array {
        $contradictions = [];
        
        // Look for negation patterns
        $positives = [];
        $negatives = [];
        
        foreach ($relations as $rel) {
            $target = strtolower($rel['to']);
            
            if ($this->containsNegation($target)) {
                $negatives[] = $rel;
            } else {
                $positives[] = $rel;
            }
        }
        
        // If we have both positives and negatives, potential contradiction
        if (!empty($positives) && !empty($negatives)) {
            $contradictions[] = array_merge($positives, $negatives);
        }
        
        return $contradictions;
    }
    
    /**
     * Check if string contains negation
     */
    private function containsNegation(string $text): bool {
        $negations = ['not', 'no', 'never', 'none', 'neither', 'without', 'lack'];
        
        foreach ($negations as $neg) {
            if (str_contains($text, $neg)) {
                return true;
            }
        }
        
        return false;
    }
}