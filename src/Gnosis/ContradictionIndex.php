<?php
namespace ZionXMemory\Gnosis;

use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;

/**
 * ContradictionIndex - Finds and tracks contradictions
 * 
 * @package ZionXMemory\Gnosis
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class ContradictionIndex {
    private StorageAdapterInterface $storage;
    private ?AIAdapterInterface $ai = null;
    
    public function __construct(StorageAdapterInterface $storage, ?AIAdapterInterface $ai = null) {
        $this->storage = $storage;
        $this->ai = $ai;
    }
    
    public function find(string $tenantId, string $claim, array $belief, ?array $existingBeliefs = null): array {
        // Get all active beliefs
        if ($existingBeliefs === null) {
            $pattern = "gnosis:{$tenantId}:belief:*";
            $allBeliefs = $this->storage->query(['pattern' => $pattern]);
        } else {
            $allBeliefs = $existingBeliefs;
        }
        
        $contradictions = [];
        
        foreach ($allBeliefs as $otherBelief) {
            if ($otherBelief['id'] === $belief['id']) {
                continue;
            }
            
            // Check for contradictions
            if ($this->areContradictory($claim, $otherBelief['claim'])) {
                $contradictions[] = [
                    'belief_id' => $otherBelief['id'],
                    'claim' => $otherBelief['claim'],
                    'state' => $otherBelief['state'],
                    'confidence' => $otherBelief['confidence']
                ];
            }
        }
        
        return $contradictions;
    }
    
    public function index(string $tenantId, string $beliefId1, string $beliefId2, string $contradictionType): void {
        $contradictionId = $this->generateContradictionId($beliefId1, $beliefId2);
        $key = $this->buildContradictionKey($tenantId, $contradictionId);
        
        $record = [
            'id' => $contradictionId,
            'belief_1' => $beliefId1,
            'belief_2' => $beliefId2,
            'type' => $contradictionType,
            'discovered_at' => time(),
            'resolved' => false
        ];
        
        $this->storage->write($key, $record, [
            'tenant' => $tenantId,
            'type' => 'contradiction'
        ]);
    }
    
    public function getAll(string $tenantId): array {
        $pattern = $this->buildContradictionKey($tenantId, '*');
        $contradictions = $this->storage->query(['pattern' => $pattern]);
        
        return array_filter($contradictions, fn($c) => !($c['resolved'] ?? false));
    }
    
    private function areContradictory(string $claim1, string $claim2): bool {
        // Prefer AI-based contradiction detection when available.
        if ($this->ai) {
            try {
                $det = $this->ai->detectContradiction($claim1, $claim2);
                if ($det === true) return true;
                if ($det === false) return false;
                // if null, fall through to heuristic
            } catch (\Throwable $e) {
                // If AI fails, fall back to heuristic below
            }
        }

        // Heuristic fallback
        $claim1Lower = strtolower($claim1);
        $claim2Lower = strtolower($claim2);

        // Check for negation patterns
        $negations = ['not', 'never', 'no', 'false', 'incorrect'];

        foreach ($negations as $neg) {
            if (str_contains($claim1Lower, $neg) && !str_contains($claim2Lower, $neg)) {
                return true;
            }
            if (str_contains($claim2Lower, $neg) && !str_contains($claim1Lower, $neg)) {
                return true;
            }
        }

        return false;
    }
    
    private function buildContradictionKey(string $tenantId, string $contradictionId): string {
        return "contradictions:{$tenantId}:{$contradictionId}";
    }
    
    private function generateContradictionId(string $beliefId1, string $beliefId2): string {
        $ids = [$beliefId1, $beliefId2];
        sort($ids);
        return 'contra_' . hash('sha256', implode(':', $ids));
    }
}