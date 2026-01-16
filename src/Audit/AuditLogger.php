<?php
namespace ZionXMemory\Audit;

use ZionXMemory\Contracts\AuditInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;

/**
 * AuditLogger - Tamper-evident audit logging
 * All operations are logged with cryptographic integrity
 * 
 * @package ZionXMemory\Audit
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class AuditLogger implements AuditInterface {
    private StorageAdapterInterface $storage;
    private array $auditChain = [];
    
    public function __construct(StorageAdapterInterface $storage) {
        $this->storage = $storage;
    }
    
    public function log(string $tenantId, string $operation, array $data, array $context): string {
        $auditId = $this->generateAuditId();
        $timestamp = $context['timestamp'] ?? time();
        
        // Get previous hash for chain integrity
        $previousHash = $this->getLastAuditHash($tenantId);
        
        $auditRecord = [
            'id' => $auditId,
            'tenant_id' => $tenantId,
            'operation' => $operation,
            'data' => $data,
            'context' => $context,
            'timestamp' => $timestamp,
            'previous_hash' => $previousHash,
            'hash' => null // Will be calculated below
        ];
        
        // Calculate hash for tamper evidence
        $auditRecord['hash'] = $this->calculateHash($auditRecord);
        
        // Store audit record
        $key = $this->buildAuditKey($tenantId, $auditId);
        $this->storage->write($key, $auditRecord, [
            'tenant' => $tenantId,
            'type' => 'audit',
            'immutable' => true,
            'timestamp' => $timestamp
        ]);
        
        // Update last hash reference
        $this->updateLastHash($tenantId, $auditRecord['hash'], $timestamp);
        
        return $auditId;
    }
    
    public function verify(string $auditId): bool {
        // Verify audit record integrity
        $pattern = "audit:*:{$auditId}";
        $records = $this->storage->query(['pattern' => $pattern]);
        
        if (empty($records)) {
            return false;
        }
        
        $record = $records[0];
        $storedHash = $record['hash'];
        
        // Recalculate hash
        $record['hash'] = null;
        $calculatedHash = $this->calculateHash($record);
        
        return $storedHash === $calculatedHash;
    }
    
    public function getAuditTrail(string $tenantId, array $filters): array {
        $pattern = $this->buildAuditKey($tenantId, '*');
        $allRecords = $this->storage->query(['pattern' => $pattern]);
        
        // Apply filters
        $filtered = array_filter($allRecords, function($record) use ($filters) {
            if (isset($filters['operation']) && $record['operation'] !== $filters['operation']) {
                return false;
            }
            
            if (isset($filters['after']) && $record['timestamp'] < $filters['after']) {
                return false;
            }
            
            if (isset($filters['before']) && $record['timestamp'] > $filters['before']) {
                return false;
            }
            
            return true;
        });
        
        // Sort by timestamp
        usort($filtered, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        return array_values($filtered);
    }
    
    public function replay(string $tenantId, int $fromTimestamp, int $toTimestamp): array {
        $trail = $this->getAuditTrail($tenantId, [
            'after' => $fromTimestamp,
            'before' => $toTimestamp
        ]);
        
        $operations = [];
        foreach ($trail as $record) {
            $operations[] = [
                'timestamp' => $record['timestamp'],
                'operation' => $record['operation'],
                'data' => $record['data']
            ];
        }
        
        return $operations;
    }
    
    /**
     * Verify chain integrity for a tenant
     */
    public function verifyChainIntegrity(string $tenantId): array {
        $trail = $this->getAuditTrail($tenantId, []);
        
        $results = [
            'valid' => true,
            'total_records' => count($trail),
            'broken_links' => []
        ];
        
        for ($i = 1; $i < count($trail); $i++) {
            $current = $trail[$i];
            $previous = $trail[$i - 1];
            
            if ($current['previous_hash'] !== $previous['hash']) {
                $results['valid'] = false;
                $results['broken_links'][] = [
                    'position' => $i,
                    'audit_id' => $current['id'],
                    'expected_hash' => $previous['hash'],
                    'actual_hash' => $current['previous_hash']
                ];
            }
        }
        
        return $results;
    }
    
    private function calculateHash(array $record): string {
        // Remove hash field for calculation
        $data = $record;
        unset($data['hash']);
        
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES));
    }
    
    private function getLastAuditHash(string $tenantId): ?string {
        $key = "audit:last_hash:{$tenantId}";
        $data = $this->storage->read($key);
        
        return $data['hash'] ?? null;
    }
    
    private function updateLastHash(string $tenantId, string $hash, int $timestamp): void {
        $key = "audit:last_hash:{$tenantId}";
        $this->storage->write($key, [
            'hash' => $hash,
            'timestamp' => $timestamp
        ], ['tenant' => $tenantId]);
    }
    
    private function buildAuditKey(string $tenantId, string $auditId): string {
        return "audit:{$tenantId}:{$auditId}";
    }
    
    private function generateAuditId(): string {
        return 'audit_' . bin2hex(random_bytes(16));
    }
}