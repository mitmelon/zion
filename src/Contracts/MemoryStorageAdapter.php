<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface MemoryStorageAdapter
 * 
 * Defines the contract for memory storage adapters.
 * Implementations can be file-based, database-based (MySQL, MongoDB), etc.
 * All operations must be tenant-isolated.
 * 
 * @package Zion\Memory\Contracts
 */
interface MemoryStorageAdapter
{
    /**
     * Store a message in memory.
     *
     * @param string $tenantId Unique tenant identifier for isolation
     * @param string $sessionId Session identifier for grouping related messages
     * @param array $message Message data including role, content, timestamp, metadata
     * @return string Unique message ID
     */
    public function storeMessage(string $tenantId, string $sessionId, array $message): string;

    /**
     * Retrieve messages for a session.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $sessionId Session identifier
     * @param int $limit Maximum number of messages to retrieve
     * @param int $offset Starting offset for pagination
     * @return array Array of messages
     */
    public function getMessages(string $tenantId, string $sessionId, int $limit = 50, int $offset = 0): array;

    /**
     * Store a summary for a session.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $sessionId Session identifier
     * @param string $summary The AI-generated summary text
     * @param array $metadata Additional metadata about the summary
     * @return string Unique summary ID
     */
    public function storeSummary(string $tenantId, string $sessionId, string $summary, array $metadata = []): string;

    /**
     * Get the latest summary for a session.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $sessionId Session identifier
     * @return array|null Summary data or null if none exists
     */
    public function getLatestSummary(string $tenantId, string $sessionId): ?array;

    /**
     * Get all summaries for a session.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $sessionId Session identifier
     * @return array Array of summaries
     */
    public function getSummaries(string $tenantId, string $sessionId): array;

    /**
     * Delete old messages based on time decay.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $sessionId Session identifier
     * @param int $olderThanSeconds Delete messages older than this many seconds
     * @return int Number of messages deleted
     */
    public function pruneMessages(string $tenantId, string $sessionId, int $olderThanSeconds): int;

    /**
     * Get message count for a session.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $sessionId Session identifier
     * @return int Number of messages
     */
    public function getMessageCount(string $tenantId, string $sessionId): int;

    /**
     * Clear all messages for a session.
     *
     * @param string $tenantId Unique tenant identifier
     * @param string $sessionId Session identifier
     * @return bool True on success
     */
    public function clearSession(string $tenantId, string $sessionId): bool;

    /**
     * Check if the storage adapter is healthy and accessible.
     *
     * @return bool True if healthy
     */
    public function healthCheck(): bool;
}
