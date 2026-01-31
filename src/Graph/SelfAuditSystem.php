<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\EpistemicStatusInterface;
use ZionXMemory\Contracts\GraphConsistencyCheckerInterface;
use ZionXMemory\Contracts\SelfAuditInterface;

/**
 * SelfAuditSystem
 * CRITICAL: System self-examination for wisdom compounding
 * "What do we believe strongly with weak evidence?"
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class SelfAuditSystem implements SelfAuditInterface {
    private StorageAdapterInterface $storage;
    private EpistemicStatusInterface $epistemic;
    private GraphConsistencyCheckerInterface $consistency;
    
    public function __construct(
        StorageAdapterInterface $storage,
        EpistemicStatusInterface $epistemic,
        GraphConsistencyCheckerInterface $consistency
    ) {
        $this->storage = $storage;
        $this->epistemic = $epistemic;
        $this->consistency = $consistency;
    }
    
    /**
     * Find strongly-believed claims with weak evidence
     * CRITICAL: Exposes overconfidence
     */
    public function findWeaklySupported(string $tenantId, array $thresholds): array {
        $minConfidence = $thresholds['min_confidence'] ?? 0.7;
        $maxEvidence = $thresholds['max_evidence'] ?? 2;
        
        $pattern = "institutional:{$tenantId}:*";
        $claims = $this->storage->query(['pattern' => $pattern]);
        
        $weaklySupported = [];
        
        foreach ($claims as $claim) {
            $confidence = $claim['confidence']['mean'] ?? 0;
            $evidenceCount = count($claim['evidence'] ?? []);
            
            if ($confidence >= $minConfidence && $evidenceCount <= $maxEvidence) {
                $weaklySupported[] = [
                    'claim_id' => $claim['id'],
                    'claim' => $claim['claim'],
                    'confidence' => $confidence,
                    'evidence_count' => $evidenceCount,
                    'risk_score' => $confidence / max(1, $evidenceCount) // High confidence, low evidence = high risk
                ];
            }
        }
        
        // Sort by risk score
        usort($weaklySupported, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);
        
        return $weaklySupported;
    }
    
    /**
     * Find contradictions with high confidence on both sides
     */
    public function findHighConfidenceConflicts(string $tenantId): array {
        $summary = $this->consistency->getContradictionSummary($tenantId);
        
        // Return only high severity conflicts
        return $summary['details']['high'] ?? [];
    }
    
    /**
     * Analyze reasoning quality over time period
     */
    public function analyzeReasoningQuality(string $tenantId, array $period): array {
        $startTime = $period['start'] ?? (time() - 2592000); // Default 30 days
        $endTime = $period['end'] ?? time();
        
        // Get all claims in period
        $pattern = "institutional:{$tenantId}:*";

        // Optimize: Filter by promoted_at or timestamp (fallback) at the storage level
        $criteria = [
            'pattern' => $pattern,
            'filter' => [
                [
                    'field' => ['promoted_at', 'timestamp'],
                    'operator' => '>=',
                    'value' => $startTime
                ],
                [
                    'field' => ['promoted_at', 'timestamp'],
                    'operator' => '<=',
                    'value' => $endTime
                ]
            ]
        ];

        $allClaims = $this->storage->query($criteria);
        
        $periodClaims = array_filter($allClaims, function($claim) use ($startTime, $endTime) {
            $timestamp = $claim['promoted_at'] ?? $claim['timestamp'] ?? 0;
            return $timestamp >= $startTime && $timestamp <= $endTime;
        });
        
        // Analyze epistemic basis
        $claimIds = array_column($periodClaims, 'id');
        $basis = $this->epistemic->getReasoningBasis($tenantId, $claimIds);
        
        return [
            'period' => ['start' => $startTime, 'end' => $endTime],
            'total_claims' => count($periodClaims),
            'reasoning_basis' => $basis,
            'quality_score' => $this->calculateQualityScore($basis),
            'recommendations' => $this->generateRecommendations($basis)
        ];
    }
    
    /**
     * Get wisdom metrics
     * CRITICAL: How is wisdom compounding?
     */
    public function getWisdomMetrics(string $tenantId): array {
        // Get institutional memory
        $institutional = $this->storage->query(['pattern' => "institutional:{$tenantId}:*"]);
        
        // Get minority opinions
        $minorityOpinions = $this->storage->query(['pattern' => "minority_opinion:{$tenantId}:*"]);
        
        // Get verified minority opinions
        $verifiedCorrect = array_filter($minorityOpinions, fn($o) => $o['proven_correct'] === true);
        
        // Get epistemic distribution
        $hypotheses = $this->epistemic->getClaimsByStatus($tenantId, EpistemicStatusInterface::STATUS_HYPOTHESIS);
        $evidence = $this->epistemic->getClaimsByStatus($tenantId, EpistemicStatusInterface::STATUS_EVIDENCE);
        $confirmed = $this->epistemic->getClaimsByStatus($tenantId, EpistemicStatusInterface::STATUS_CONFIRMED);
        
        // Wisdom score
        $wisdomScore = $this->calculateWisdomScore([
            'institutional_count' => count($institutional),
            'evidence_ratio' => count($evidence) / max(1, count($institutional)),
            'minority_accuracy' => count($verifiedCorrect) / max(1, count($minorityOpinions)),
            'confirmation_rate' => count($confirmed) / max(1, count($hypotheses) + count($confirmed))
        ]);
        
        return [
            'institutional_memory_count' => count($institutional),
            'hypothesis_count' => count($hypotheses),
            'evidence_count' => count($evidence),
            'confirmed_count' => count($confirmed),
            'minority_opinions' => count($minorityOpinions),
            'minority_correct' => count($verifiedCorrect),
            'minority_accuracy' => count($verifiedCorrect) / max(1, count($minorityOpinions)),
            'wisdom_score' => $wisdomScore,
            'trending' => $this->calculateTrend($tenantId, $institutional)
        ];
    }
    
    private function calculateQualityScore(array $basis): float {
        $factRatio = $basis['fact_ratio'];
        $assumptionRatio = $basis['assumption_ratio'];
        
        // Quality = high fact ratio, low assumption ratio
        return ($factRatio * 0.7) + ((1 - $assumptionRatio) * 0.3);
    }
    
    private function generateRecommendations(array $basis): array {
        $recommendations = [];
        
        if ($basis['fact_ratio'] < 0.4) {
            $recommendations[] = "Low fact ratio - gather more evidence";
        }
        
        if ($basis['assumption_ratio'] > 0.5) {
            $recommendations[] = "High assumption ratio - validate assumptions";
        }
        
        if ($basis['reasoning_quality'] === 'weak') {
            $recommendations[] = "Reasoning quality weak - strengthen evidence base";
        }
        
        return $recommendations;
    }
    
    private function calculateWisdomScore(array $metrics): float {
        return (
            ($metrics['evidence_ratio'] * 0.3) +
            ($metrics['minority_accuracy'] * 0.3) +
            ($metrics['confirmation_rate'] * 0.2) +
            (min(1.0, log($metrics['institutional_count'] + 1) / 10) * 0.2)
        );
    }
    
    private function calculateTrend(string $tenantId, array $items = null): string {
        $now = time();
        $day = 86400;
        $week = 7 * $day;

        // Time windows
        $lastStart = $now - $week;
        $prevStart = $now - (2 * $week);
        $prevEnd = $lastStart - 1;

        // Check if indices are built (Optimization)
        if (!$this->storage->exists("institutional_indices_built:{$tenantId}")) {
            $this->buildInstitutionalIndices($tenantId);
        }

        $lastCount = $this->countItemsInPeriod($tenantId, $lastStart, $now);
        $prevCount = $this->countItemsInPeriod($tenantId, $prevStart, $prevEnd);

        // Compute percent change safely
        if ($prevCount === 0) {
            $percentChange = ($lastCount === 0) ? 0.0 : 100.0;
        } else {
            $percentChange = (($lastCount - $prevCount) / max(1, $prevCount)) * 100.0;
        }

        // Classify trend
        if ($percentChange > 10.0) {
            $trend = 'increasing';
        } elseif ($percentChange < -10.0) {
            $trend = 'decreasing';
        } else {
            $trend = 'stable';
        }

        // If counts are very low but volatile, mark as volatile
        if (($lastCount + $prevCount) > 0 && max($lastCount, $prevCount) <= 3 && abs($percentChange) >= 50.0) {
            $trend = 'volatile';
        }

        // Persist a short summary and a timestamped history entry
        $summaryKey = "wisdom_trend:{$tenantId}";
        $historyKey = "wisdom_trend_history:{$tenantId}:" . $now;

        $summary = [
            'tenant' => $tenantId,
            'trend' => $trend,
            'last_week_count' => $lastCount,
            'previous_week_count' => $prevCount,
            'percent_change' => $percentChange,
            'computed_at' => $now
        ];

        try {
            $this->storage->write($summaryKey, $summary, ['tenant' => $tenantId, 'type' => 'wisdom_trend_summary']);
            $this->storage->write($historyKey, $summary, ['tenant' => $tenantId, 'type' => 'wisdom_trend_history']);
        } catch (\Throwable $e) {
            // Persisting trend is best-effort; ignore write errors but keep computed trend
        }

        return $trend;
    }

    private function buildInstitutionalIndices(string $tenantId): void {
        $pattern = "institutional:{$tenantId}:*";
        $items = $this->storage->query(['pattern' => $pattern]);

        foreach ($items as $item) {
            $ts = $item['promoted_at'] ?? $item['timestamp'] ?? 0;
            if ($ts > 0) {
                $date = date('Ymd', $ts);
                $indexKey = "index:institutional:{$tenantId}:{$date}";
                $this->storage->addToSet($indexKey, $item['id'], ['type' => 'institutional_index']);
            }
        }
        $this->storage->write("institutional_indices_built:{$tenantId}", true, ['type' => 'system_flag']);
    }

    private function countItemsInPeriod(string $tenantId, int $start, int $end): int {
        $count = 0;

        // Loop through days
        $current = $start;
        while ($current <= $end) {
            $date = date('Ymd', $current);
            $nextDay = strtotime("$date +1 day");
            $dayStart = strtotime($date); // 00:00:00
            $dayEnd = $nextDay - 1; // 23:59:59

            $indexKey = "index:institutional:{$tenantId}:{$date}";

            // Check if day is FULLY inside the range [start, end]
            if ($dayStart >= $start && $dayEnd <= $end) {
                // Use fast count
                $count += $this->storage->getSetCount($indexKey);
            } else {
                // Partial day: fetch members and check precise timestamp
                $ids = $this->storage->getSetMembers($indexKey);
                if (!empty($ids)) {
                    // Fetch items to check timestamp
                    // Construct keys
                    $keys = array_map(fn($id) => "institutional:{$tenantId}:{$id}", $ids);
                    $items = $this->storage->readMulti($keys);

                    foreach ($items as $item) {
                        if (!$item) continue;
                        $ts = $item['promoted_at'] ?? $item['timestamp'] ?? 0;
                        if ($ts >= $start && $ts <= $end) {
                            $count++;
                        }
                    }
                }
            }

            // Move to next day
            $current = $nextDay;
        }

        return $count;
    }
}