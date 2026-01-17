<?php
namespace ZionXMemory\Graph;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;
use ZionXMemory\Contracts\MinorityOpinionInterface;

/**
 * MinorityOpinionTracker
 * CRITICAL: Preserves dissent and tracks accuracy
 * Prevents premature convergence and surfaces "often-right dissenters"
 * 
 * @package ZionXMemory\Graph
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class MinorityOpinionTracker implements MinorityOpinionInterface {
    private StorageAdapterInterface $storage;
    private AuditInterface $audit;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AuditInterface $audit
    ) {
        $this->storage = $storage;
        $this->audit = $audit;
    }
    
    /**
     * Record minority opinion
     */
    public function recordMinorityOpinion(
        string $tenantId,
        string $sessionId,
        array $opinion
    ): void {
        $opinionId = 'minority_' . bin2hex(random_bytes(8));
        
        $record = [
            'id' => $opinionId,
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'agent_id' => $opinion['agent_id'],
            'position' => $opinion['position'],
            'reasoning' => $opinion['reasoning'] ?? [],
            'confidence' => $opinion['confidence'] ?? 0.5,
            'majority_position' => $opinion['majority_position'] ?? null,
            'recorded_at' => time(),
            'outcome' => null, // Will be updated later
            'proven_correct' => null
        ];
        
        $key = $this->buildOpinionKey($tenantId, $opinionId);
        $this->storage->write($key, $record, [
            'tenant' => $tenantId,
            'type' => 'minority_opinion'
        ]);
        
        // Index by agent
        $this->indexByAgent($tenantId, $opinion['agent_id'], $opinionId);
        
        // Index by topic
        if (isset($opinion['topic'])) {
            $this->indexByTopic($tenantId, $opinion['topic'], $opinionId);
        }
        
        $this->audit->log($tenantId, 'minority_opinion_recorded', [
            'opinion_id' => $opinionId,
            'agent_id' => $opinion['agent_id'],
            'session_id' => $sessionId
        ], ['timestamp' => time()]);
    }
    
    /**
     * Track minority accuracy over time
     * Updates outcome when ground truth is established
     */
    public function trackAccuracy(
        string $tenantId,
        string $agentId,
        array $outcomes
    ): void {
        foreach ($outcomes as $outcome) {
            $opinionId = $outcome['opinion_id'];
            $key = $this->buildOpinionKey($tenantId, $opinionId);
            $opinion = $this->storage->read($key);
            
            if (!$opinion) continue;
            
            // Update outcome
            $opinion['outcome'] = $outcome['actual_outcome'];
            $opinion['proven_correct'] = $this->comparePositions(
                $opinion['position'],
                $outcome['actual_outcome']
            );
            $opinion['verified_at'] = time();
            
            $this->storage->write($key, $opinion, ['tenant' => $tenantId]);
            
            // Update agent accuracy stats
            $this->updateAgentAccuracy($tenantId, $agentId, $opinion['proven_correct']);
        }
        
        $this->audit->log($tenantId, 'minority_accuracy_tracked', [
            'agent_id' => $agentId,
            'outcomes_processed' => count($outcomes)
        ], ['timestamp' => time()]);
    }
    
    /**
     * Get "often-right dissenters"
     * CRITICAL: Surfaces agents who are correct when disagreeing with majority
     */
    public function getReliableDissenters(
        string $tenantId,
        array $criteria = []
    ): array {
        $minAccuracy = $criteria['min_accuracy'] ?? 0.6;
        $minOpinions = $criteria['min_opinions'] ?? 3;
        
        $pattern = "minority_accuracy:{$tenantId}:*";
        $agentStats = $this->storage->query(['pattern' => $pattern]);
        
        $reliableDissenters = [];
        
        foreach ($agentStats as $stat) {
            $accuracy = $stat['accuracy'];
            $totalOpinions = $stat['total_opinions'];
            
            if ($accuracy >= $minAccuracy && $totalOpinions >= $minOpinions) {
                $reliableDissenters[] = [
                    'agent_id' => $stat['agent_id'],
                    'accuracy' => $accuracy,
                    'total_opinions' => $totalOpinions,
                    'correct_count' => $stat['correct_count'],
                    'reliability_score' => $this->calculateReliabilityScore($stat)
                ];
            }
        }
        
        // Sort by reliability score
        usort($reliableDissenters, fn($a, $b) => $b['reliability_score'] <=> $a['reliability_score']);
        
        return $reliableDissenters;
    }
    
    /**
     * Get preserved dissent for topic
     */
    public function getDissent(string $tenantId, string $topic): array {
        $indexKey = "minority_index:{$tenantId}:topic:{$topic}";
        $opinionIds = $this->storage->read($indexKey) ?? [];
        
        $dissent = [];
        foreach ($opinionIds as $opinionId) {
            $opinion = $this->storage->read($this->buildOpinionKey($tenantId, $opinionId));
            if ($opinion) {
                $dissent[] = $opinion;
            }
        }
        
        return $dissent;
    }
    
    /**
     * Compare positions to determine correctness
     */
    private function comparePositions($minorityPosition, $actualOutcome): bool {
        // Simplified comparison - in production use semantic similarity
        return strtolower(trim($minorityPosition)) === strtolower(trim($actualOutcome));
    }
    
    /**
     * Update agent accuracy statistics
     */
    private function updateAgentAccuracy(string $tenantId, string $agentId, bool $correct): void {
        $key = "minority_accuracy:{$tenantId}:{$agentId}";
        $stats = $this->storage->read($key) ?? [
            'agent_id' => $agentId,
            'total_opinions' => 0,
            'correct_count' => 0,
            'accuracy' => 0.0
        ];
        
        $stats['total_opinions']++;
        if ($correct) {
            $stats['correct_count']++;
        }
        
        $stats['accuracy'] = $stats['correct_count'] / $stats['total_opinions'];
        
        $this->storage->write($key, $stats, ['tenant' => $tenantId]);
    }
    
    /**
     * Calculate reliability score
     * Balances accuracy with volume
     */
    private function calculateReliabilityScore(array $stat): float {
        $accuracy = $stat['accuracy'];
        $volume = $stat['total_opinions'];
        
        // Reliability = accuracy * log(volume + 1)
        // More opinions = more reliable
        return $accuracy * log($volume + 1);
    }
    
    private function buildOpinionKey(string $tenantId, string $opinionId): string {
        return "minority_opinion:{$tenantId}:{$opinionId}";
    }
    
    private function indexByAgent(string $tenantId, string $agentId, string $opinionId): void {
        $key = "minority_index:{$tenantId}:agent:{$agentId}";
        $index = $this->storage->read($key) ?? [];
        $index[] = $opinionId;
        $this->storage->write($key, $index, ['tenant' => $tenantId]);
    }
    
    private function indexByTopic(string $tenantId, string $topic, string $opinionId): void {
        $key = "minority_index:{$tenantId}:topic:{$topic}";
        $index = $this->storage->read($key) ?? [];
        $index[] = $opinionId;
        $this->storage->write($key, $index, ['tenant' => $tenantId]);
    }
}