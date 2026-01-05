<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface AuditLoggerInterface
 * 
 * Defines the contract for audit logging in the banking AI memory system.
 * All operations must be logged for compliance and audit purposes.
 * Logs must be tamper-evident and secure.
 * 
 * @package Zion\Memory\Contracts
 */
interface AuditLoggerInterface
{
    /**
     * Log action types.
     */
    public const ACTION_MESSAGE_STORED = 'message_stored';
    public const ACTION_MESSAGE_RETRIEVED = 'message_retrieved';
    public const ACTION_SUMMARY_GENERATED = 'summary_generated';
    public const ACTION_FACT_EXTRACTED = 'fact_extracted';
    public const ACTION_FACT_STORED = 'fact_stored';
    public const ACTION_FACT_UPDATED = 'fact_updated';
    public const ACTION_FACT_DELETED = 'fact_deleted';
    public const ACTION_CONTRADICTION_DETECTED = 'contradiction_detected';
    public const ACTION_CONFLICT_RESOLVED = 'conflict_resolved';
    public const ACTION_AGENT_RESPONSE = 'agent_response';
    public const ACTION_AGENT_CONSENSUS = 'agent_consensus';
    public const ACTION_MEMORY_PRUNED = 'memory_pruned';
    public const ACTION_SESSION_CLEARED = 'session_cleared';
    public const ACTION_GRAPH_QUERY = 'graph_query';

    /**
     * Log an action.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $action Action type (use class constants)
     * @param array $data Action-specific data
     * @param array $metadata Additional metadata
     * @return string Log entry ID
     */
    public function log(string $tenantId, string $action, array $data, array $metadata = []): string;

    /**
     * Log a security-sensitive action with enhanced tracking.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $action Action type
     * @param array $data Action-specific data
     * @param string $actorId ID of the actor performing the action
     * @param string $actorType Type of actor (user, agent, system)
     * @return string Log entry ID
     */
    public function logSecure(
        string $tenantId,
        string $action,
        array $data,
        string $actorId,
        string $actorType = 'system'
    ): string;

    /**
     * Retrieve audit logs for a tenant.
     *
     * @param string $tenantId Unique tenant identifier
     * @param array $filters Filters (action, date_from, date_to, actor_id, etc.)
     * @param int $limit Maximum number of logs
     * @param int $offset Starting offset
     * @return array Array of log entries
     */
    public function getLogs(string $tenantId, array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Get a specific log entry by ID.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $logId Log entry ID
     * @return array|null Log entry or null if not found
     */
    public function getLogById(string $tenantId, string $logId): ?array;

    /**
     * Verify the integrity of audit logs.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string|null $fromLogId Starting log ID for verification
     * @param string|null $toLogId Ending log ID for verification
     * @return bool True if logs are intact, false if tampered
     */
    public function verifyIntegrity(string $tenantId, ?string $fromLogId = null, ?string $toLogId = null): bool;

    /**
     * Export audit logs for compliance reporting.
     *
     * @param string $tenantId Unique tenant identifier
     * @param array $filters Filters for export
     * @param string $format Export format (json, csv, xml)
     * @return string Exported data
     */
    public function export(string $tenantId, array $filters = [], string $format = 'json'): string;

    /**
     * Get audit statistics for a tenant.
     *
     * @param string $tenantId Unique tenant identifier
     * @param \DateTimeInterface|null $from Start date
     * @param \DateTimeInterface|null $to End date
     * @return array Statistics array
     */
    public function getStatistics(
        string $tenantId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array;

    /**
     * Set the storage adapter for audit logs.
     *
     * @param object $storageAdapter Storage adapter instance
     * @return void
     */
    public function setStorageAdapter(object $storageAdapter): void;
}
