<?php

declare(strict_types=1);

namespace Zion\Memory\Audit;

use Zion\Memory\Contracts\AuditLoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Class AuditLogger
 * 
 * Audit logging implementation for banking AI memory system.
 * Logs all operations with tamper-evident hashing.
 * Supports compliance reporting and export.
 * 
 * @package Zion\Memory\Audit
 */
class AuditLogger implements AuditLoggerInterface
{
    /**
     * @var string Base storage path
     */
    private string $basePath;

    /**
     * @var string Hashing algorithm
     */
    private string $hashAlgorithm = 'sha256';

    /**
     * @var string|null Previous log hash for chain integrity
     */
    private ?string $previousHash = null;

    /**
     * @var array In-memory buffer for batch writing
     */
    private array $buffer = [];

    /**
     * @var int Buffer flush threshold
     */
    private int $bufferThreshold = 100;

    /**
     * @var object|null External storage adapter
     */
    private ?object $storageAdapter = null;

    /**
     * Constructor.
     *
     * @param string $basePath Base path for log storage
     * @param array $config Configuration options
     */
    public function __construct(string $basePath, array $config = [])
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->ensureDirectory($this->basePath);
        
        if (isset($config['hash_algorithm'])) {
            $this->hashAlgorithm = $config['hash_algorithm'];
        }
        
        if (isset($config['buffer_threshold'])) {
            $this->bufferThreshold = $config['buffer_threshold'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function log(string $tenantId, string $action, array $data, array $metadata = []): string
    {
        return $this->createLogEntry($tenantId, $action, $data, $metadata);
    }

    /**
     * {@inheritdoc}
     */
    public function logSecure(
        string $tenantId,
        string $action,
        array $data,
        string $actorId,
        string $actorType = 'system'
    ): string {
        $metadata = [
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'secure' => true,
        ];
        
        return $this->createLogEntry($tenantId, $action, $data, $metadata);
    }

    /**
     * {@inheritdoc}
     */
    public function getLogs(string $tenantId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $logs = $this->loadLogs($tenantId);
        
        // Apply filters
        if (!empty($filters)) {
            $logs = array_filter($logs, function ($log) use ($filters) {
                foreach ($filters as $key => $value) {
                    switch ($key) {
                        case 'action':
                            if ($log['action'] !== $value) return false;
                            break;
                        case 'date_from':
                            if ($log['timestamp'] < strtotime($value)) return false;
                            break;
                        case 'date_to':
                            if ($log['timestamp'] > strtotime($value)) return false;
                            break;
                        case 'actor_id':
                            if (($log['metadata']['actor_id'] ?? null) !== $value) return false;
                            break;
                        case 'actor_type':
                            if (($log['metadata']['actor_type'] ?? null) !== $value) return false;
                            break;
                    }
                }
                return true;
            });
        }
        
        // Sort by timestamp descending
        usort($logs, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        
        // Apply pagination
        return array_slice($logs, $offset, $limit);
    }

    /**
     * {@inheritdoc}
     */
    public function getLogById(string $tenantId, string $logId): ?array
    {
        $logs = $this->loadLogs($tenantId);
        
        foreach ($logs as $log) {
            if ($log['id'] === $logId) {
                return $log;
            }
        }
        
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function verifyIntegrity(string $tenantId, ?string $fromLogId = null, ?string $toLogId = null): bool
    {
        $logs = $this->loadLogs($tenantId);
        
        if (empty($logs)) {
            return true;
        }
        
        // Sort by timestamp
        usort($logs, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        // Find range
        $startIndex = 0;
        $endIndex = count($logs) - 1;
        
        if ($fromLogId !== null) {
            foreach ($logs as $index => $log) {
                if ($log['id'] === $fromLogId) {
                    $startIndex = $index;
                    break;
                }
            }
        }
        
        if ($toLogId !== null) {
            foreach ($logs as $index => $log) {
                if ($log['id'] === $toLogId) {
                    $endIndex = $index;
                    break;
                }
            }
        }
        
        // Verify hash chain
        $previousHash = null;
        
        for ($i = $startIndex; $i <= $endIndex; $i++) {
            $log = $logs[$i];
            
            // Verify this log's hash
            $expectedHash = $this->calculateHash($log, $previousHash);
            
            if ($log['hash'] !== $expectedHash) {
                return false;
            }
            
            // Verify chain link
            if ($previousHash !== null && $log['previous_hash'] !== $previousHash) {
                return false;
            }
            
            $previousHash = $log['hash'];
        }
        
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function export(string $tenantId, array $filters = [], string $format = 'json'): string
    {
        $logs = $this->getLogs($tenantId, $filters, PHP_INT_MAX);
        
        return match ($format) {
            'json' => json_encode($logs, JSON_PRETTY_PRINT),
            'csv' => $this->exportToCsv($logs),
            'xml' => $this->exportToXml($logs),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function getStatistics(
        string $tenantId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        $logs = $this->loadLogs($tenantId);
        
        // Apply date filters
        if ($from !== null) {
            $logs = array_filter($logs, fn($l) => $l['timestamp'] >= $from->getTimestamp());
        }
        
        if ($to !== null) {
            $logs = array_filter($logs, fn($l) => $l['timestamp'] <= $to->getTimestamp());
        }
        
        $stats = [
            'total_entries' => count($logs),
            'actions' => [],
            'actors' => [],
            'daily_counts' => [],
            'period' => [
                'from' => $from?->format(\DateTimeInterface::ATOM),
                'to' => $to?->format(\DateTimeInterface::ATOM),
            ],
        ];
        
        foreach ($logs as $log) {
            // Count by action
            $action = $log['action'];
            $stats['actions'][$action] = ($stats['actions'][$action] ?? 0) + 1;
            
            // Count by actor
            $actorId = $log['metadata']['actor_id'] ?? 'system';
            $stats['actors'][$actorId] = ($stats['actors'][$actorId] ?? 0) + 1;
            
            // Count by day
            $day = date('Y-m-d', $log['timestamp']);
            $stats['daily_counts'][$day] = ($stats['daily_counts'][$day] ?? 0) + 1;
        }
        
        // Sort daily counts
        ksort($stats['daily_counts']);
        
        return $stats;
    }

    /**
     * {@inheritdoc}
     */
    public function setStorageAdapter(object $storageAdapter): void
    {
        $this->storageAdapter = $storageAdapter;
    }

    /**
     * Flush the buffer to storage.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach ($this->buffer as $tenantId => $logs) {
            $existingLogs = $this->loadLogs($tenantId, false);
            $existingLogs = array_merge($existingLogs, $logs);
            $this->saveLogs($tenantId, $existingLogs);
        }
        
        $this->buffer = [];
    }

    /**
     * Destructor - ensure buffer is flushed.
     */
    public function __destruct()
    {
        $this->flush();
    }

    /**
     * Create a log entry.
     *
     * @param string $tenantId Tenant ID
     * @param string $action Action type
     * @param array $data Action data
     * @param array $metadata Metadata
     * @return string Log entry ID
     */
    private function createLogEntry(string $tenantId, string $action, array $data, array $metadata): string
    {
        $logId = Uuid::uuid4()->toString();
        $timestamp = time();
        
        $entry = [
            'id' => $logId,
            'tenant_id' => $tenantId,
            'action' => $action,
            'data' => $data,
            'metadata' => array_merge($metadata, [
                'component' => $metadata['component'] ?? 'unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]),
            'timestamp' => $timestamp,
            'datetime' => date(\DateTimeInterface::ATOM, $timestamp),
            'previous_hash' => $this->previousHash,
        ];
        
        // Calculate tamper-evident hash
        $entry['hash'] = $this->calculateHash($entry, $this->previousHash);
        $this->previousHash = $entry['hash'];
        
        // Add to buffer
        if (!isset($this->buffer[$tenantId])) {
            $this->buffer[$tenantId] = [];
        }
        $this->buffer[$tenantId][] = $entry;
        
        // Flush if buffer threshold reached
        if (count($this->buffer[$tenantId]) >= $this->bufferThreshold) {
            $this->flush();
        }
        
        return $logId;
    }

    /**
     * Calculate hash for a log entry.
     *
     * @param array $entry Log entry
     * @param string|null $previousHash Previous hash in chain
     * @return string Hash
     */
    private function calculateHash(array $entry, ?string $previousHash): string
    {
        $hashData = [
            'id' => $entry['id'],
            'tenant_id' => $entry['tenant_id'],
            'action' => $entry['action'],
            'data' => $entry['data'],
            'timestamp' => $entry['timestamp'],
            'previous_hash' => $previousHash,
        ];
        
        return hash($this->hashAlgorithm, json_encode($hashData));
    }

    /**
     * Load logs for a tenant.
     *
     * @param string $tenantId Tenant ID
     * @param bool $includeBuffer Include buffered entries
     * @return array Logs
     */
    private function loadLogs(string $tenantId, bool $includeBuffer = true): array
    {
        $filePath = $this->getLogFilePath($tenantId);
        
        $logs = [];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $logs = json_decode($content, true) ?? [];
        }
        
        // Include buffered logs
        if ($includeBuffer && isset($this->buffer[$tenantId])) {
            $logs = array_merge($logs, $this->buffer[$tenantId]);
        }
        
        return $logs;
    }

    /**
     * Save logs for a tenant.
     *
     * @param string $tenantId Tenant ID
     * @param array $logs Logs to save
     * @return void
     */
    private function saveLogs(string $tenantId, array $logs): void
    {
        $filePath = $this->getLogFilePath($tenantId);
        $dir = dirname($filePath);
        
        $this->ensureDirectory($dir);
        
        file_put_contents($filePath, json_encode($logs, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Get log file path for a tenant.
     *
     * @param string $tenantId Tenant ID
     * @return string File path
     */
    private function getLogFilePath(string $tenantId): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tenantId);
        return $this->basePath . DIRECTORY_SEPARATOR . $sanitized . DIRECTORY_SEPARATOR . 'audit.json';
    }

    /**
     * Ensure directory exists.
     *
     * @param string $path Directory path
     * @return void
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Export logs to CSV format.
     *
     * @param array $logs Logs to export
     * @return string CSV content
     */
    private function exportToCsv(array $logs): string
    {
        if (empty($logs)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Header
        fputcsv($output, ['id', 'tenant_id', 'action', 'timestamp', 'datetime', 'data', 'metadata', 'hash']);
        
        // Data rows
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['tenant_id'],
                $log['action'],
                $log['timestamp'],
                $log['datetime'],
                json_encode($log['data']),
                json_encode($log['metadata']),
                $log['hash'],
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Export logs to XML format.
     *
     * @param array $logs Logs to export
     * @return string XML content
     */
    private function exportToXml(array $logs): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><audit_logs/>');
        
        foreach ($logs as $log) {
            $entry = $xml->addChild('entry');
            $entry->addChild('id', htmlspecialchars($log['id']));
            $entry->addChild('tenant_id', htmlspecialchars($log['tenant_id']));
            $entry->addChild('action', htmlspecialchars($log['action']));
            $entry->addChild('timestamp', (string) $log['timestamp']);
            $entry->addChild('datetime', htmlspecialchars($log['datetime']));
            $entry->addChild('hash', htmlspecialchars($log['hash']));
            
            $dataNode = $entry->addChild('data');
            $this->arrayToXml($log['data'], $dataNode);
            
            $metaNode = $entry->addChild('metadata');
            $this->arrayToXml($log['metadata'], $metaNode);
        }
        
        return $xml->asXML();
    }

    /**
     * Convert array to XML nodes.
     *
     * @param array $data Data array
     * @param \SimpleXMLElement $xml XML element
     * @return void
     */
    private function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $key = is_numeric($key) ? "item_{$key}" : $key;
            
            if (is_array($value)) {
                $subNode = $xml->addChild($key);
                $this->arrayToXml($value, $subNode);
            } else {
                $xml->addChild($key, htmlspecialchars((string) $value));
            }
        }
    }
}
