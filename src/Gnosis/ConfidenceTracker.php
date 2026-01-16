<?php
namespace ZionXMemory\Gnosis;

/**
 * ConfidenceTracker - Tracks confidence ranges over time
 * 
 * @package ZionXMemory\Gnosis
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class ConfidenceTracker {
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    
    public function __construct(StorageAdapterInterface $storage, AIAdapterInterface $ai) {
        $this->storage = $storage;
        $this->ai = $ai;
    }
    
    public function record(string $tenantId, string $beliefId, array $confidence, int $timestamp): void {
        $key = $this->buildConfidenceKey($tenantId, $beliefId, $timestamp);
        
        $record = [
            'belief_id' => $beliefId,
            'confidence' => $confidence,
            'timestamp' => $timestamp
        ];
        
        $this->storage->write($key, $record, [
            'tenant' => $tenantId,
            'type' => 'confidence',
            'immutable' => true
        ]);
    }
    
    public function getHistory(string $tenantId, string $beliefId): array {
        $pattern = $this->buildConfidenceKey($tenantId, $beliefId, '*');
        $records = $this->storage->query(['pattern' => $pattern]);
        
        usort($records, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        return $records;
    }
    
    public function analyzeConfidenceDrift(string $tenantId, string $beliefId): array {
        $history = $this->getHistory($tenantId, $beliefId);
        
        if (count($history) < 2) {
            return [
                'drift' => 'insufficient_data',
                'trend' => null
            ];
        }
        
        $first = $history[0]['confidence']['mean'];
        $last = $history[count($history) - 1]['confidence']['mean'];
        
        $drift = $last - $first;
        
        return [
            'drift' => $drift,
            'trend' => $drift > 0.1 ? 'increasing' : ($drift < -0.1 ? 'decreasing' : 'stable'),
            'history_points' => count($history)
        ];
    }
    
    private function buildConfidenceKey(string $tenantId, string $beliefId, $timestamp): string {
        return "confidence:{$tenantId}:{$beliefId}:{$timestamp}";
    }
}