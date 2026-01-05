<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface CacheInterface
 * 
 * Defines the contract for caching in the banking AI memory system.
 * Used for performance optimization of frequent queries.
 * 
 * @package Zion\Memory\Contracts
 */
interface CacheInterface
{
    /**
     * Get a cached value.
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Set a cached value.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds (0 = forever)
     * @return bool True on success
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Check if a key exists in cache.
     *
     * @param string $key Cache key
     * @return bool True if exists
     */
    public function has(string $key): bool;

    /**
     * Delete a cached value.
     *
     * @param string $key Cache key
     * @return bool True on success
     */
    public function delete(string $key): bool;

    /**
     * Delete multiple cached values.
     *
     * @param array $keys Array of cache keys
     * @return int Number of keys deleted
     */
    public function deleteMany(array $keys): int;

    /**
     * Clear all cached values for a pattern.
     *
     * @param string $pattern Key pattern (e.g., "tenant:123:*")
     * @return int Number of keys cleared
     */
    public function clearPattern(string $pattern): int;

    /**
     * Clear all cached values.
     *
     * @return bool True on success
     */
    public function clear(): bool;

    /**
     * Get multiple cached values.
     *
     * @param array $keys Array of cache keys
     * @return array Associative array of key => value pairs
     */
    public function getMany(array $keys): array;

    /**
     * Set multiple cached values.
     *
     * @param array $values Associative array of key => value pairs
     * @param int $ttl Time to live in seconds
     * @return bool True on success
     */
    public function setMany(array $values, int $ttl = 3600): bool;

    /**
     * Get cache statistics.
     *
     * @return array Statistics array
     */
    public function getStats(): array;
}
