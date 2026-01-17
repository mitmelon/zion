<?php
namespace ZionXMemory\Gnosis;

use ZionXMemory\Contracts\GnosisInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;


/**
 * EpistemicState - Tracks belief lifecycle and confidence
 * Never enforces correctness, only records epistemic state
 * 
 * @package ZionXMemory\Gnosis
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class EpistemicState implements GnosisInterface {
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    private AuditInterface $audit;
    private BeliefLifecycle $lifecycle;
    private ConfidenceTracker $confidence;
    private ContradictionIndex $contradictions;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AIAdapterInterface $ai,
        AuditInterface $audit
    ) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->audit = $audit;
        $this->lifecycle = new BeliefLifecycle($storage);
        $this->confidence = new ConfidenceTracker($storage, $ai);
        $this->contradictions = new ContradictionIndex($storage, $ai);
    }
    
    public function recordBelief(string $tenantId, string $claim, array $confidence, array $provenance): string {
        $beliefId = $this->generateBeliefId();
        $timestamp = time();
        
        // Score confidence using AI
        $epistemicScore = $this->ai->scoreEpistemicConfidence($claim, [
            'provenance' => $provenance,
            'context' => []
        ]);
        
        $belief = [
            'id' => $beliefId,
            'tenant_id' => $tenantId,
            'claim' => $claim,
            'confidence' => [
                'min' => $confidence['min'] ?? $epistemicScore['min'],
                'max' => $confidence['max'] ?? $epistemicScore['max'],
                'mean' => $confidence['mean'] ?? $epistemicScore['mean']
            ],
            'state' => BeliefLifecycle::STATE_HYPOTHESIS,
            'provenance' => $provenance,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'version' => 1
        ];
        
        $key = $this->buildBeliefKey($tenantId, $beliefId);
        $this->storage->write($key, $belief, [
            'tenant' => $tenantId,
            'type' => 'belief',
            'immutable' => false
        ]);
        
        // Initialize lifecycle
        $this->lifecycle->initialize($tenantId, $beliefId, $timestamp);
        
        // Track confidence
        $this->confidence->record($tenantId, $beliefId, $belief['confidence'], $timestamp);
        
        $this->audit->log($tenantId, 'belief_recorded', [
            'belief_id' => $beliefId,
            'claim' => $claim,
            'state' => BeliefLifecycle::STATE_HYPOTHESIS
        ], ['timestamp' => $timestamp]);
        
        return $beliefId;
    }
    
    public function updateBeliefState(string $tenantId, string $beliefId, string $newState, string $reason): bool {
        $key = $this->buildBeliefKey($tenantId, $beliefId);
        $belief = $this->storage->read($key);
        
        if (!$belief) {
            return false;
        }
        
        $timestamp = time();
        $oldState = $belief['state'];
        
        // Validate state transition
        if (!$this->lifecycle->canTransition($oldState, $newState)) {
            return false;
        }
        
        // Create version record (append-only)
        $versionId = $this->generateVersionId();
        $versionKey = $this->buildVersionKey($tenantId, $beliefId, $versionId);
        
        $version = $belief;
        $version['version_id'] = $versionId;
        $version['previous_state'] = $oldState;
        $version['state'] = $newState;
        $version['transition_reason'] = $reason;
        $version['updated_at'] = $timestamp;
        $version['version']++;
        
        $this->storage->write($versionKey, $version, [
            'tenant' => $tenantId,
            'type' => 'belief_version',
            'immutable' => true
        ]);
        
        // Update current belief (non-destructive)
        $belief['state'] = $newState;
        $belief['updated_at'] = $timestamp;
        $belief['version']++;
        $belief['last_transition'] = [
            'from' => $oldState,
            'to' => $newState,
            'reason' => $reason,
            'timestamp' => $timestamp
        ];
        
        $this->storage->write($key, $belief, [
            'tenant' => $tenantId,
            'type' => 'belief'
        ]);
        
        // Update lifecycle
        $this->lifecycle->transition($tenantId, $beliefId, $oldState, $newState, $reason, $timestamp);
        
        $this->audit->log($tenantId, 'belief_state_updated', [
            'belief_id' => $beliefId,
            'old_state' => $oldState,
            'new_state' => $newState,
            'reason' => $reason
        ], ['timestamp' => $timestamp]);
        
        return true;
    }
    
    public function getBeliefHistory(string $tenantId, string $beliefId): array {
        $history = [];
        
        // Get original belief
        $key = $this->buildBeliefKey($tenantId, $beliefId);
        $current = $this->storage->read($key);
        
        if (!$current) {
            return [];
        }
        
        // Get all versions
        $versionPattern = $this->buildVersionKey($tenantId, $beliefId, '*');
        $versions = $this->storage->query(['pattern' => $versionPattern]);
        
        // Sort by version
        usort($versions, fn($a, $b) => $a['version'] <=> $b['version']);
        
        foreach ($versions as $version) {
            $history[] = [
                'version' => $version['version'],
                'state' => $version['state'],
                'confidence' => $version['confidence'],
                'timestamp' => $version['updated_at'],
                'transition' => $version['last_transition'] ?? null
            ];
        }
        
        return $history;
    }
    
    public function findContradictions(string $tenantId, string $beliefId): array {
        $key = $this->buildBeliefKey($tenantId, $beliefId);
        $belief = $this->storage->read($key);
        
        if (!$belief) {
            return [];
        }
        
        // Find contradicting beliefs
        return $this->contradictions->find($tenantId, $belief['claim'], $belief);
    }
    
    public function getEpistemicSnapshot(string $tenantId, int $timestamp): array {
        // Get all beliefs at a specific point in time
        $pattern = $this->buildBeliefKey($tenantId, '*');
        $allBeliefs = $this->storage->query(['pattern' => $pattern]);
        
        $snapshot = [
            'timestamp' => $timestamp,
            'beliefs' => [],
            'stats' => [
                'total' => 0,
                'by_state' => []
            ]
        ];
        
        foreach ($allBeliefs as $belief) {
            // Find version at timestamp
            $versionAtTime = $this->getBeliefAtTimestamp($tenantId, $belief['id'], $timestamp);
            
            if ($versionAtTime) {
                $snapshot['beliefs'][] = $versionAtTime;
                $snapshot['stats']['total']++;
                
                $state = $versionAtTime['state'];
                $snapshot['stats']['by_state'][$state] = ($snapshot['stats']['by_state'][$state] ?? 0) + 1;
            }
        }
        
        return $snapshot;
    }
    
    /**
     * Get belief state at specific timestamp
     */
    private function getBeliefAtTimestamp(string $tenantId, string $beliefId, int $timestamp): ?array {
        $history = $this->getBeliefHistory($tenantId, $beliefId);
        
        // Find latest version before timestamp
        $found = null;
        foreach ($history as $version) {
            if ($version['timestamp'] <= $timestamp) {
                $found = $version;
            } else {
                break;
            }
        }
        
        return $found;
    }
    
    private function buildBeliefKey(string $tenantId, string $beliefId): string {
        return "gnosis:{$tenantId}:belief:{$beliefId}";
    }
    
    private function buildVersionKey(string $tenantId, string $beliefId, string $versionId): string {
        return "gnosis:{$tenantId}:belief:{$beliefId}:version:{$versionId}";
    }
    
    private function generateBeliefId(): string {
        return 'belief_' . bin2hex(random_bytes(16));
    }
    
    private function generateVersionId(): string {
        return 'ver_' . bin2hex(random_bytes(8));
    }
}