<?php
namespace ZionXMemory\Gnosis;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;

/**
 * BeliefLifecycle - Manages belief state transitions
 * Defines valid state transitions and records lifecycle events
 * 
 * @package ZionXMemory\Gnosis
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class BeliefLifecycle {
    const STATE_HYPOTHESIS = 'hypothesis';
    const STATE_ACCEPTED = 'accepted';
    const STATE_CONTESTED = 'contested';
    const STATE_DEPRECATED = 'deprecated';
    const STATE_REJECTED = 'rejected';
    
    private StorageAdapterInterface $storage;
    private array $validTransitions;
    
    public function __construct(StorageAdapterInterface $storage) {
        $this->storage = $storage;
        $this->validTransitions = $this->defineValidTransitions();
    }
    
    public function initialize(string $tenantId, string $beliefId, int $timestamp): void {
        $lifecycleKey = $this->buildLifecycleKey($tenantId, $beliefId);
        
        $lifecycle = [
            'belief_id' => $beliefId,
            'current_state' => self::STATE_HYPOTHESIS,
            'transitions' => [],
            'created_at' => $timestamp
        ];
        
        $this->storage->write($lifecycleKey, $lifecycle, [
            'tenant' => $tenantId,
            'type' => 'lifecycle'
        ]);
    }
    
    public function canTransition(string $fromState, string $toState): bool {
        if (!isset($this->validTransitions[$fromState])) {
            return false;
        }
        
        return in_array($toState, $this->validTransitions[$fromState]);
    }
    
    public function transition(string $tenantId, string $beliefId, string $fromState, string $toState, string $reason, int $timestamp): void {
        $lifecycleKey = $this->buildLifecycleKey($tenantId, $beliefId);
        $lifecycle = $this->storage->read($lifecycleKey);
        
        if (!$lifecycle) {
            return;
        }
        
        $lifecycle['transitions'][] = [
            'from' => $fromState,
            'to' => $toState,
            'reason' => $reason,
            'timestamp' => $timestamp
        ];
        $lifecycle['current_state'] = $toState;
        
        $this->storage->write($lifecycleKey, $lifecycle, [
            'tenant' => $tenantId,
            'type' => 'lifecycle'
        ]);
    }
    
    private function defineValidTransitions(): array {
        return [
            self::STATE_HYPOTHESIS => [
                self::STATE_ACCEPTED,
                self::STATE_CONTESTED,
                self::STATE_REJECTED
            ],
            self::STATE_ACCEPTED => [
                self::STATE_CONTESTED,
                self::STATE_DEPRECATED
            ],
            self::STATE_CONTESTED => [
                self::STATE_ACCEPTED,
                self::STATE_REJECTED,
                self::STATE_DEPRECATED
            ],
            self::STATE_DEPRECATED => [
                // Can be revived if new evidence emerges
                self::STATE_CONTESTED
            ],
            self::STATE_REJECTED => [
                // Final state, but can be reconsidered
                self::STATE_HYPOTHESIS
            ]
        ];
    }
    
    private function buildLifecycleKey(string $tenantId, string $beliefId): string {
        return "lifecycle:{$tenantId}:{$beliefId}";
    }
}