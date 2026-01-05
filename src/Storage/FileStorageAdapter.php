<?php

declare(strict_types=1);

namespace Zion\Memory\Storage;

use Zion\Memory\Contracts\MemoryStorageAdapter;
use Zion\Memory\Contracts\CacheInterface;
use Ramsey\Uuid\Uuid;

/**
 * Class FileStorageAdapter
 * 
 * File-based implementation of MemoryStorageAdapter.
 * Stores messages and summaries as JSON files with caching support.
 * Implements multi-tenant isolation through directory structure.
 * 
 * @package Zion\Memory\Storage
 */
class FileStorageAdapter implements MemoryStorageAdapter
{
    /**
     * @var string Base storage path
     */
    private string $basePath;

    /**
     * @var CacheInterface|null Cache instance
     */
    private ?CacheInterface $cache;

    /**
     * @var int Default cache TTL in seconds
     */
    private int $cacheTtl;

    /**
     * Constructor.
     *
     * @param string $basePath Base path for file storage
     * @param CacheInterface|null $cache Optional cache instance
     * @param int $cacheTtl Cache TTL in seconds
     */
    public function __construct(
        string $basePath,
        ?CacheInterface $cache = null,
        int $cacheTtl = 3600
    ) {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
        
        $this->ensureDirectory($this->basePath);
    }

    /**
     * {@inheritdoc}
     */
    public function storeMessage(string $tenantId, string $sessionId, array $message): string
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        $messageId = Uuid::uuid4()->toString();
        $message['id'] = $messageId;
        $message['timestamp'] = $message['timestamp'] ?? time();
        $message['tenant_id'] = $tenantId;
        $message['session_id'] = $sessionId;

        $messages = $this->loadMessages($tenantId, $sessionId);
        $messages[] = $message;

        $this->saveMessages($tenantId, $sessionId, $messages);
        $this->invalidateMessageCache($tenantId, $sessionId);

        return $messageId;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessages(string $tenantId, string $sessionId, int $limit = 50, int $offset = 0): array
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        $cacheKey = "messages:{$tenantId}:{$sessionId}:{$limit}:{$offset}";
        
        if ($this->cache && ($cached = $this->cache->get($cacheKey)) !== null) {
            return $cached;
        }

        $messages = $this->loadMessages($tenantId, $sessionId);
        
        // Sort by timestamp descending and apply pagination
        usort($messages, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
        $result = array_slice($messages, $offset, $limit);

        if ($this->cache) {
            $this->cache->set($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function storeSummary(string $tenantId, string $sessionId, string $summary, array $metadata = []): string
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        $summaryId = Uuid::uuid4()->toString();
        $summaryData = [
            'id' => $summaryId,
            'summary' => $summary,
            'timestamp' => time(),
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'metadata' => $metadata,
        ];

        $summaries = $this->loadSummaries($tenantId, $sessionId);
        $summaries[] = $summaryData;

        $this->saveSummaries($tenantId, $sessionId, $summaries);
        $this->invalidateSummaryCache($tenantId, $sessionId);

        return $summaryId;
    }

    /**
     * {@inheritdoc}
     */
    public function getLatestSummary(string $tenantId, string $sessionId): ?array
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        $cacheKey = "latest_summary:{$tenantId}:{$sessionId}";
        
        if ($this->cache && ($cached = $this->cache->get($cacheKey)) !== null) {
            return $cached ?: null;
        }

        $summaries = $this->loadSummaries($tenantId, $sessionId);
        
        if (empty($summaries)) {
            if ($this->cache) {
                $this->cache->set($cacheKey, false, $this->cacheTtl);
            }
            return null;
        }

        usort($summaries, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
        $latest = $summaries[0];

        if ($this->cache) {
            $this->cache->set($cacheKey, $latest, $this->cacheTtl);
        }

        return $latest;
    }

    /**
     * {@inheritdoc}
     */
    public function getSummaries(string $tenantId, string $sessionId): array
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        return $this->loadSummaries($tenantId, $sessionId);
    }

    /**
     * {@inheritdoc}
     */
    public function pruneMessages(string $tenantId, string $sessionId, int $olderThanSeconds): int
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        $messages = $this->loadMessages($tenantId, $sessionId);
        $cutoff = time() - $olderThanSeconds;
        $originalCount = count($messages);

        $messages = array_filter($messages, fn($m) => ($m['timestamp'] ?? 0) >= $cutoff);
        
        $this->saveMessages($tenantId, $sessionId, array_values($messages));
        $this->invalidateMessageCache($tenantId, $sessionId);

        return $originalCount - count($messages);
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageCount(string $tenantId, string $sessionId): int
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        return count($this->loadMessages($tenantId, $sessionId));
    }

    /**
     * {@inheritdoc}
     */
    public function clearSession(string $tenantId, string $sessionId): bool
    {
        $this->validateTenantId($tenantId);
        $this->validateSessionId($sessionId);

        $sessionPath = $this->getSessionPath($tenantId, $sessionId);
        
        if (is_dir($sessionPath)) {
            $this->deleteDirectory($sessionPath);
        }

        $this->invalidateMessageCache($tenantId, $sessionId);
        $this->invalidateSummaryCache($tenantId, $sessionId);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): bool
    {
        return is_dir($this->basePath) && is_writable($this->basePath);
    }

    /**
     * Get the session storage path.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return string Session path
     */
    private function getSessionPath(string $tenantId, string $sessionId): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 
               $this->sanitizePath($tenantId) . DIRECTORY_SEPARATOR . 
               'sessions' . DIRECTORY_SEPARATOR . 
               $this->sanitizePath($sessionId);
    }

    /**
     * Load messages from file.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return array Messages array
     */
    private function loadMessages(string $tenantId, string $sessionId): array
    {
        $filePath = $this->getSessionPath($tenantId, $sessionId) . DIRECTORY_SEPARATOR . 'messages.json';
        
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Save messages to file.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param array $messages Messages to save
     * @return void
     */
    private function saveMessages(string $tenantId, string $sessionId, array $messages): void
    {
        $sessionPath = $this->getSessionPath($tenantId, $sessionId);
        $this->ensureDirectory($sessionPath);
        
        $filePath = $sessionPath . DIRECTORY_SEPARATOR . 'messages.json';
        file_put_contents($filePath, json_encode($messages, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Load summaries from file.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return array Summaries array
     */
    private function loadSummaries(string $tenantId, string $sessionId): array
    {
        $filePath = $this->getSessionPath($tenantId, $sessionId) . DIRECTORY_SEPARATOR . 'summaries.json';
        
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Save summaries to file.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param array $summaries Summaries to save
     * @return void
     */
    private function saveSummaries(string $tenantId, string $sessionId, array $summaries): void
    {
        $sessionPath = $this->getSessionPath($tenantId, $sessionId);
        $this->ensureDirectory($sessionPath);
        
        $filePath = $sessionPath . DIRECTORY_SEPARATOR . 'summaries.json';
        file_put_contents($filePath, json_encode($summaries, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Ensure a directory exists.
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
     * Recursively delete a directory.
     *
     * @param string $path Directory path
     * @return void
     */
    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        
        rmdir($path);
    }

    /**
     * Sanitize a path component to prevent directory traversal.
     *
     * @param string $path Path component
     * @return string Sanitized path
     */
    private function sanitizePath(string $path): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $path);
    }

    /**
     * Validate tenant ID format.
     *
     * @param string $tenantId Tenant ID
     * @throws \InvalidArgumentException If invalid
     */
    private function validateTenantId(string $tenantId): void
    {
        if (empty($tenantId) || strlen($tenantId) > 128) {
            throw new \InvalidArgumentException('Invalid tenant ID');
        }
    }

    /**
     * Validate session ID format.
     *
     * @param string $sessionId Session ID
     * @throws \InvalidArgumentException If invalid
     */
    private function validateSessionId(string $sessionId): void
    {
        if (empty($sessionId) || strlen($sessionId) > 128) {
            throw new \InvalidArgumentException('Invalid session ID');
        }
    }

    /**
     * Invalidate message cache for a session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return void
     */
    private function invalidateMessageCache(string $tenantId, string $sessionId): void
    {
        if ($this->cache) {
            $this->cache->clearPattern("messages:{$tenantId}:{$sessionId}:*");
        }
    }

    /**
     * Invalidate summary cache for a session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return void
     */
    private function invalidateSummaryCache(string $tenantId, string $sessionId): void
    {
        if ($this->cache) {
            $this->cache->delete("latest_summary:{$tenantId}:{$sessionId}");
        }
    }
}
