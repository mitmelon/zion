<?php

declare(strict_types=1);

namespace Zion\Memory\Storage;

use Zion\Memory\Contracts\CacheInterface;

/**
 * Class InMemoryCache
 * 
 * Simple in-memory cache implementation for performance optimization.
 * Used for caching recent messages, summaries, and graph queries.
 * 
 * @package Zion\Memory\Storage
 */
class InMemoryCache implements CacheInterface
{
    /**
     * @var array Cache storage
     */
    private array $cache = [];

    /**
     * @var array Expiration times
     */
    private array $expirations = [];

    /**
     * @var int Hit count for statistics
     */
    private int $hits = 0;

    /**
     * @var int Miss count for statistics
     */
    private int $misses = 0;

    /**
     * @var int Maximum cache size
     */
    private int $maxSize;

    /**
     * Constructor.
     *
     * @param int $maxSize Maximum number of items to cache
     */
    public function __construct(int $maxSize = 10000)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $this->cleanExpired($key);

        if (isset($this->cache[$key])) {
            $this->hits++;
            return $this->cache[$key];
        }

        $this->misses++;
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        // Evict oldest entries if cache is full
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$key])) {
            $this->evictOldest();
        }

        $this->cache[$key] = $value;
        
        if ($ttl > 0) {
            $this->expirations[$key] = time() + $ttl;
        } else {
            unset($this->expirations[$key]);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        $this->cleanExpired($key);
        return isset($this->cache[$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        unset($this->cache[$key], $this->expirations[$key]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMany(array $keys): int
    {
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->cache[$key])) {
                $count++;
            }
            $this->delete($key);
        }
        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function clearPattern(string $pattern): int
    {
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $count = 0;

        foreach (array_keys($this->cache) as $key) {
            if (preg_match($regex, $key)) {
                $this->delete($key);
                $count++;
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $this->cache = [];
        $this->expirations = [];
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMany(array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function setMany(array $values, int $ttl = 3600): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        return [
            'items' => count($this->cache),
            'max_size' => $this->maxSize,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => $this->hits + $this->misses > 0 
                ? round($this->hits / ($this->hits + $this->misses) * 100, 2) 
                : 0,
            'memory_usage' => $this->estimateMemoryUsage(),
        ];
    }

    /**
     * Clean expired entries for a specific key.
     *
     * @param string $key Key to check
     * @return void
     */
    private function cleanExpired(string $key): void
    {
        if (isset($this->expirations[$key]) && $this->expirations[$key] < time()) {
            unset($this->cache[$key], $this->expirations[$key]);
        }
    }

    /**
     * Evict oldest entries when cache is full.
     *
     * @return void
     */
    private function evictOldest(): void
    {
        // Remove 10% of entries
        $toRemove = (int) ceil($this->maxSize * 0.1);
        $keys = array_keys($this->cache);
        
        for ($i = 0; $i < $toRemove && $i < count($keys); $i++) {
            $this->delete($keys[$i]);
        }
    }

    /**
     * Estimate memory usage of the cache.
     *
     * @return int Estimated bytes
     */
    private function estimateMemoryUsage(): int
    {
        $memory = 0;
        foreach ($this->cache as $key => $value) {
            $memory += strlen($key);
            $memory += strlen(serialize($value));
        }
        return $memory;
    }
}
