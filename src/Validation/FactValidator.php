<?php

declare(strict_types=1);

namespace Zion\Memory\Validation;

use Zion\Memory\Contracts\FactValidatorInterface;
use Zion\Memory\Contracts\GraphMemoryAdapter;
use Zion\Memory\Contracts\ValidationResult;

/**
 * Class FactValidator
 * 
 * Validates facts against existing graph memory.
 * Detects contradictions and duplicates.
 * 
 * @package Zion\Memory\Validation
 */
class FactValidator implements FactValidatorInterface
{
    /**
     * @var array Validation rules
     */
    private array $rules = [
        'require_entity' => true,
        'require_type' => true,
        'min_confidence' => 0.5,
        'check_duplicates' => true,
        'check_contradictions' => true,
        'similarity_threshold' => 0.8,
    ];

    /**
     * @var array Contradiction rules by entity type
     */
    private array $contradictionRules = [];

    /**
     * Constructor.
     *
     * @param array $rules Optional validation rules
     */
    public function __construct(array $rules = [])
    {
        $this->rules = array_merge($this->rules, $rules);
        $this->initContradictionRules();
    }

    /**
     * {@inheritdoc}
     */
    public function validate(string $tenantId, array $newFact, GraphMemoryAdapter $graphAdapter): ValidationResult
    {
        $warnings = [];
        $contradictions = [];
        $duplicate = null;
        $isValid = true;
        $confidenceScore = 1.0;

        // Basic validation
        if ($this->rules['require_entity'] && empty($newFact['entity'])) {
            $warnings[] = 'Missing entity name';
            $isValid = false;
        }

        if ($this->rules['require_type'] && empty($newFact['type'])) {
            $warnings[] = 'Missing entity type';
            $confidenceScore *= 0.8;
        }

        // Check confidence threshold
        $factConfidence = $newFact['confidence'] ?? 1.0;
        if ($factConfidence < $this->rules['min_confidence']) {
            $warnings[] = "Low confidence score: {$factConfidence}";
            $confidenceScore *= $factConfidence;
        }

        // Check for duplicates
        if ($this->rules['check_duplicates']) {
            $duplicate = $this->findDuplicate($tenantId, $newFact, $graphAdapter);
        }

        // Check for contradictions
        if ($this->rules['check_contradictions']) {
            $contradictions = $this->findContradictions($tenantId, $newFact, $graphAdapter);
        }

        // Adjust validity based on contradictions
        if (!empty($contradictions)) {
            $isValid = false;
            $confidenceScore *= 0.5;
        }

        return new ValidationResult(
            isValid: $isValid && $duplicate === null,
            contradictions: $contradictions,
            duplicate: $duplicate,
            warnings: $warnings,
            confidenceScore: max(0, min(1, $confidenceScore)),
            metadata: [
                'validated_at' => time(),
                'fact_entity' => $newFact['entity'] ?? null,
                'fact_type' => $newFact['type'] ?? null,
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function validateBatch(string $tenantId, array $facts, GraphMemoryAdapter $graphAdapter): array
    {
        return array_map(
            fn($fact) => $this->validate($tenantId, $fact, $graphAdapter),
            $facts
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findContradictions(string $tenantId, array $newFact, GraphMemoryAdapter $graphAdapter): array
    {
        $contradictions = [];
        $entityName = $newFact['entity'] ?? '';
        $entityType = $newFact['type'] ?? '';

        if (empty($entityName)) {
            return [];
        }

        // Find existing fact for same entity
        $existingFact = $graphAdapter->queryByEntity($tenantId, $entityName);

        if (!$existingFact) {
            // No existing fact, check by attributes
            return $this->findAttributeContradictions($tenantId, $newFact, $graphAdapter);
        }

        // Check for type contradictions
        if (!empty($entityType) && !empty($existingFact['type'])) {
            if ($existingFact['type'] !== $entityType) {
                // Type mismatch might be a contradiction
                $contradictions[] = array_merge($existingFact, [
                    'contradiction_type' => 'type_mismatch',
                    'existing_type' => $existingFact['type'],
                    'new_type' => $entityType,
                ]);
            }
        }

        // Check attribute contradictions
        $attrContradictions = $this->checkAttributeContradictions(
            $existingFact['attributes'] ?? [],
            $newFact['attributes'] ?? [],
            $entityType
        );

        if (!empty($attrContradictions)) {
            $contradictions[] = array_merge($existingFact, [
                'contradiction_type' => 'attribute_conflict',
                'conflicting_attributes' => $attrContradictions,
            ]);
        }

        return $contradictions;
    }

    /**
     * {@inheritdoc}
     */
    public function findDuplicate(string $tenantId, array $fact, GraphMemoryAdapter $graphAdapter): ?array
    {
        $entityName = $fact['entity'] ?? '';
        
        if (empty($entityName)) {
            return null;
        }

        $existingFact = $graphAdapter->queryByEntity($tenantId, $entityName);

        if (!$existingFact) {
            return null;
        }

        // Calculate similarity
        $similarity = $this->calculateSimilarity($fact, $existingFact);

        if ($similarity >= $this->rules['similarity_threshold']) {
            return $existingFact;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function calculateSimilarity(array $fact1, array $fact2): float
    {
        $scores = [];

        // Entity name similarity
        $entity1 = strtolower($fact1['entity'] ?? '');
        $entity2 = strtolower($fact2['entity'] ?? '');
        
        if ($entity1 === $entity2) {
            $scores[] = 1.0;
        } else {
            similar_text($entity1, $entity2, $percent);
            $scores[] = $percent / 100;
        }

        // Type similarity
        $type1 = strtolower($fact1['type'] ?? '');
        $type2 = strtolower($fact2['type'] ?? '');
        $scores[] = $type1 === $type2 ? 1.0 : 0.0;

        // Attribute similarity
        $attrs1 = $fact1['attributes'] ?? [];
        $attrs2 = $fact2['attributes'] ?? [];
        
        if (empty($attrs1) && empty($attrs2)) {
            $scores[] = 1.0;
        } elseif (empty($attrs1) || empty($attrs2)) {
            $scores[] = 0.0;
        } else {
            $commonKeys = array_intersect(array_keys($attrs1), array_keys($attrs2));
            $allKeys = array_unique(array_merge(array_keys($attrs1), array_keys($attrs2)));
            
            if (count($allKeys) > 0) {
                $matchingValues = 0;
                foreach ($commonKeys as $key) {
                    if ($attrs1[$key] === $attrs2[$key]) {
                        $matchingValues++;
                    }
                }
                $scores[] = $matchingValues / count($allKeys);
            } else {
                $scores[] = 0.0;
            }
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * {@inheritdoc}
     */
    public function setRules(array $rules): void
    {
        $this->rules = array_merge($this->rules, $rules);
    }

    /**
     * Get current rules.
     *
     * @return array Current rules
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Set contradiction rules for specific entity types.
     *
     * @param string $entityType Entity type
     * @param array $rules Contradiction rules
     * @return void
     */
    public function setContradictionRules(string $entityType, array $rules): void
    {
        $this->contradictionRules[$entityType] = $rules;
    }

    /**
     * Initialize default contradiction rules.
     *
     * @return void
     */
    private function initContradictionRules(): void
    {
        // Define which attributes are mutually exclusive or must match
        $this->contradictionRules = [
            'person' => [
                'single_value' => ['date_of_birth', 'ssn', 'nationality'],
                'mutable' => ['address', 'phone', 'email', 'employment_status'],
            ],
            'account' => [
                'single_value' => ['account_number', 'account_type', 'opening_date'],
                'mutable' => ['balance', 'status', 'interest_rate'],
            ],
            'transaction' => [
                'single_value' => ['amount', 'date', 'type', 'reference'],
                'mutable' => ['status'],
            ],
            'organization' => [
                'single_value' => ['registration_number', 'founded_date'],
                'mutable' => ['address', 'employee_count', 'revenue'],
            ],
        ];
    }

    /**
     * Check for attribute contradictions between existing and new facts.
     *
     * @param array $existingAttrs Existing attributes
     * @param array $newAttrs New attributes
     * @param string $entityType Entity type
     * @return array Contradicting attributes
     */
    private function checkAttributeContradictions(
        array $existingAttrs,
        array $newAttrs,
        string $entityType
    ): array {
        $contradictions = [];
        $rules = $this->contradictionRules[$entityType] ?? [];
        $singleValueAttrs = $rules['single_value'] ?? [];

        foreach ($newAttrs as $key => $newValue) {
            if (!isset($existingAttrs[$key])) {
                continue;
            }

            $existingValue = $existingAttrs[$key];

            // Check if values differ
            if ($existingValue !== $newValue) {
                // Single-value attributes are always contradictions
                if (in_array($key, $singleValueAttrs)) {
                    $contradictions[] = [
                        'attribute' => $key,
                        'existing_value' => $existingValue,
                        'new_value' => $newValue,
                        'severity' => 'high',
                    ];
                } else {
                    // Mutable attributes might be updates, not contradictions
                    $contradictions[] = [
                        'attribute' => $key,
                        'existing_value' => $existingValue,
                        'new_value' => $newValue,
                        'severity' => 'low',
                    ];
                }
            }
        }

        return $contradictions;
    }

    /**
     * Find contradictions by searching for conflicting attribute values.
     *
     * @param string $tenantId Tenant ID
     * @param array $newFact New fact
     * @param GraphMemoryAdapter $graphAdapter Graph adapter
     * @return array Contradictions
     */
    private function findAttributeContradictions(
        string $tenantId,
        array $newFact,
        GraphMemoryAdapter $graphAdapter
    ): array {
        $contradictions = [];
        $entityType = $newFact['type'] ?? '';
        $rules = $this->contradictionRules[$entityType] ?? [];
        $singleValueAttrs = $rules['single_value'] ?? [];

        // Search for facts with matching single-value attributes
        foreach ($singleValueAttrs as $attr) {
            if (!isset($newFact['attributes'][$attr])) {
                continue;
            }

            $value = $newFact['attributes'][$attr];
            $matches = $graphAdapter->searchFacts($tenantId, [
                "attributes.{$attr}" => $value,
            ]);

            foreach ($matches as $match) {
                if (($match['entity'] ?? '') !== ($newFact['entity'] ?? '')) {
                    $contradictions[] = array_merge($match, [
                        'contradiction_type' => 'unique_attribute_conflict',
                        'conflicting_attribute' => $attr,
                        'conflicting_value' => $value,
                    ]);
                }
            }
        }

        return $contradictions;
    }
}
