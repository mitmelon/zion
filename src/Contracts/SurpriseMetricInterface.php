<?php
namespace ZionXMemory\Contracts;

/**
 * SurpriseMetricInterface
 * Calculates epistemic impact and novelty scores
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

interface SurpriseMetricInterface {
    /**
     * Calculate novelty relative to existing memory
     */
    public function calculateNovelty(array $newContent, array $existingContext): float;
    
    /**
     * Calculate contradiction impact
     */
    public function calculateContradictionImpact(array $newClaim, array $existingBeliefs): float;
    
    /**
     * Calculate confidence shift magnitude
     */
    public function calculateConfidenceShift(array $oldConfidence, array $newConfidence): float;
    
    /**
     * Calculate evidence accumulation score
     */
    public function calculateEvidenceAccumulation(array $evidence): float;
    
    /**
     * Calculate agent disagreement signal
     */
    public function calculateDisagreementSignal(array $agentBeliefs): float;
    
    /**
     * Compute composite surprise score
     */
    public function computeCompositeSurprise(array $signals, array $weights): array;
}