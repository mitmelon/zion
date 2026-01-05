<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface FactValidatorInterface
 * 
 * Defines the contract for validating facts against existing graph memory.
 * Detects contradictions and inconsistencies.
 * 
 * @package Zion\Memory\Contracts
 */
interface FactValidatorInterface
{
    /**
     * Validate a new fact against existing facts.
     *
     * @param string $tenantId Unique tenant identifier
     * @param array $newFact New fact to validate
     * @param GraphMemoryAdapter $graphAdapter Graph memory adapter for querying existing facts
     * @return ValidationResult Validation result object
     */
    public function validate(string $tenantId, array $newFact, GraphMemoryAdapter $graphAdapter): ValidationResult;

    /**
     * Validate multiple facts in batch.
     *
     * @param string $tenantId Unique tenant identifier
     * @param array $facts Array of facts to validate
     * @param GraphMemoryAdapter $graphAdapter Graph memory adapter
     * @return array Array of ValidationResult objects
     */
    public function validateBatch(string $tenantId, array $facts, GraphMemoryAdapter $graphAdapter): array;

    /**
     * Check for contradictions between a new fact and existing facts.
     *
     * @param string $tenantId Unique tenant identifier
     * @param array $newFact New fact to check
     * @param GraphMemoryAdapter $graphAdapter Graph memory adapter
     * @return array Array of contradicting facts
     */
    public function findContradictions(string $tenantId, array $newFact, GraphMemoryAdapter $graphAdapter): array;

    /**
     * Check if a fact is a duplicate of an existing fact.
     *
     * @param string $tenantId Unique tenant identifier
     * @param array $fact Fact to check
     * @param GraphMemoryAdapter $graphAdapter Graph memory adapter
     * @return array|null Existing fact if duplicate, null otherwise
     */
    public function findDuplicate(string $tenantId, array $fact, GraphMemoryAdapter $graphAdapter): ?array;

    /**
     * Calculate similarity score between two facts.
     *
     * @param array $fact1 First fact
     * @param array $fact2 Second fact
     * @return float Similarity score (0.0 to 1.0)
     */
    public function calculateSimilarity(array $fact1, array $fact2): float;

    /**
     * Set validation rules.
     *
     * @param array $rules Array of validation rules
     * @return void
     */
    public function setRules(array $rules): void;
}

/**
 * Class ValidationResult
 * 
 * Represents the result of a fact validation.
 */
class ValidationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly array $contradictions = [],
        public readonly ?array $duplicate = null,
        public readonly array $warnings = [],
        public readonly float $confidenceScore = 1.0,
        public readonly array $metadata = []
    ) {}

    public function hasContradictions(): bool
    {
        return !empty($this->contradictions);
    }

    public function isDuplicate(): bool
    {
        return $this->duplicate !== null;
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'contradictions' => $this->contradictions,
            'duplicate' => $this->duplicate,
            'warnings' => $this->warnings,
            'confidence_score' => $this->confidenceScore,
            'metadata' => $this->metadata,
        ];
    }
}
