<?php

declare(strict_types=1);

namespace Zion\Memory\Storage;

use Zion\Memory\Contracts\GraphMemoryAdapter;
use Zion\Memory\Contracts\CacheInterface;
use Ramsey\Uuid\Uuid;

/**
 * Class FileGraphAdapter
 * 
 * File-based implementation of GraphMemoryAdapter.
 * Stores graph data (facts and relationships) as JSON files.
 * Implements multi-tenant isolation through directory structure.
 * 
 * @package Zion\Memory\Storage
 */
class FileGraphAdapter implements GraphMemoryAdapter
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
     * @var array In-memory index for faster lookups
     */
    private array $entityIndex = [];

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
    public function storeFact(string $tenantId, array $fact): string
    {
        $this->validateTenantId($tenantId);

        $factId = $fact['id'] ?? Uuid::uuid4()->toString();
        $fact['id'] = $factId;
        $fact['tenant_id'] = $tenantId;
        $fact['created_at'] = $fact['created_at'] ?? time();
        $fact['updated_at'] = time();

        $facts = $this->loadFacts($tenantId);
        
        // Update existing or add new
        $found = false;
        foreach ($facts as $index => $existingFact) {
            if ($existingFact['id'] === $factId) {
                $facts[$index] = $fact;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $facts[] = $fact;
        }

        $this->saveFacts($tenantId, $facts);
        $this->invalidateFactCache($tenantId);
        $this->updateEntityIndex($tenantId, $fact);

        return $factId;
    }

    /**
     * {@inheritdoc}
     */
    public function storeRelationship(
        string $tenantId,
        string $fromEntityId,
        string $toEntityId,
        string $relationType,
        array $metadata = []
    ): string {
        $this->validateTenantId($tenantId);

        $relationshipId = Uuid::uuid4()->toString();
        $relationship = [
            'id' => $relationshipId,
            'from_entity_id' => $fromEntityId,
            'to_entity_id' => $toEntityId,
            'relation_type' => $relationType,
            'tenant_id' => $tenantId,
            'metadata' => $metadata,
            'created_at' => time(),
        ];

        $relationships = $this->loadRelationships($tenantId);
        $relationships[] = $relationship;

        $this->saveRelationships($tenantId, $relationships);
        $this->invalidateRelationshipCache($tenantId);

        return $relationshipId;
    }

    /**
     * {@inheritdoc}
     */
    public function queryByType(string $tenantId, string $entityType, array $filters = []): array
    {
        $this->validateTenantId($tenantId);

        $cacheKey = "facts_by_type:{$tenantId}:{$entityType}:" . md5(serialize($filters));
        
        if ($this->cache && ($cached = $this->cache->get($cacheKey)) !== null) {
            return $cached;
        }

        $facts = $this->loadFacts($tenantId);
        $results = array_filter($facts, function ($fact) use ($entityType, $filters) {
            if (($fact['type'] ?? '') !== $entityType) {
                return false;
            }
            
            foreach ($filters as $key => $value) {
                $factValue = $fact[$key] ?? $fact['attributes'][$key] ?? null;
                if ($factValue !== $value) {
                    return false;
                }
            }
            
            return true;
        });

        $results = array_values($results);

        if ($this->cache) {
            $this->cache->set($cacheKey, $results, $this->cacheTtl);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function queryByEntity(string $tenantId, string $entityName): ?array
    {
        $this->validateTenantId($tenantId);

        $cacheKey = "fact_by_entity:{$tenantId}:{$entityName}";
        
        if ($this->cache && ($cached = $this->cache->get($cacheKey)) !== null) {
            return $cached ?: null;
        }

        $facts = $this->loadFacts($tenantId);
        
        foreach ($facts as $fact) {
            if (($fact['entity'] ?? '') === $entityName || ($fact['id'] ?? '') === $entityName) {
                if ($this->cache) {
                    $this->cache->set($cacheKey, $fact, $this->cacheTtl);
                }
                return $fact;
            }
        }

        if ($this->cache) {
            $this->cache->set($cacheKey, false, $this->cacheTtl);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getRelationships(string $tenantId, string $entityId, ?string $relationType = null): array
    {
        $this->validateTenantId($tenantId);

        $relationships = $this->loadRelationships($tenantId);
        
        return array_values(array_filter($relationships, function ($rel) use ($entityId, $relationType) {
            $matches = $rel['from_entity_id'] === $entityId || $rel['to_entity_id'] === $entityId;
            
            if ($matches && $relationType !== null) {
                $matches = $rel['relation_type'] === $relationType;
            }
            
            return $matches;
        }));
    }

    /**
     * {@inheritdoc}
     */
    public function findRelatedEntities(
        string $tenantId,
        string $entityId,
        int $depth = 2,
        array $relationTypes = []
    ): array {
        $this->validateTenantId($tenantId);

        $visited = [];
        $results = [];
        $queue = [['id' => $entityId, 'depth' => 0, 'path' => []]];

        while (!empty($queue)) {
            $current = array_shift($queue);
            
            if (isset($visited[$current['id']]) || $current['depth'] > $depth) {
                continue;
            }
            
            $visited[$current['id']] = true;
            
            if ($current['id'] !== $entityId) {
                $entity = $this->queryByEntity($tenantId, $current['id']);
                if ($entity) {
                    $results[] = [
                        'entity' => $entity,
                        'depth' => $current['depth'],
                        'path' => $current['path'],
                    ];
                }
            }
            
            $relationships = $this->getRelationships($tenantId, $current['id']);
            
            foreach ($relationships as $rel) {
                if (!empty($relationTypes) && !in_array($rel['relation_type'], $relationTypes)) {
                    continue;
                }
                
                $nextId = $rel['from_entity_id'] === $current['id'] 
                    ? $rel['to_entity_id'] 
                    : $rel['from_entity_id'];
                
                if (!isset($visited[$nextId])) {
                    $queue[] = [
                        'id' => $nextId,
                        'depth' => $current['depth'] + 1,
                        'path' => array_merge($current['path'], [$rel]),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function updateFact(string $tenantId, string $factId, array $attributes): bool
    {
        $this->validateTenantId($tenantId);

        $facts = $this->loadFacts($tenantId);
        
        foreach ($facts as $index => $fact) {
            if ($fact['id'] === $factId) {
                $facts[$index]['attributes'] = array_merge(
                    $fact['attributes'] ?? [],
                    $attributes
                );
                $facts[$index]['updated_at'] = time();
                
                $this->saveFacts($tenantId, $facts);
                $this->invalidateFactCache($tenantId);
                
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteFact(string $tenantId, string $factId): bool
    {
        $this->validateTenantId($tenantId);

        $facts = $this->loadFacts($tenantId);
        $originalCount = count($facts);
        
        $facts = array_filter($facts, fn($f) => $f['id'] !== $factId);
        
        if (count($facts) === $originalCount) {
            return false;
        }

        $this->saveFacts($tenantId, array_values($facts));
        
        // Also delete related relationships
        $relationships = $this->loadRelationships($tenantId);
        $relationships = array_filter($relationships, function ($rel) use ($factId) {
            return $rel['from_entity_id'] !== $factId && $rel['to_entity_id'] !== $factId;
        });
        $this->saveRelationships($tenantId, array_values($relationships));
        
        $this->invalidateFactCache($tenantId);
        $this->invalidateRelationshipCache($tenantId);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function searchFacts(string $tenantId, array $criteria): array
    {
        $this->validateTenantId($tenantId);

        $facts = $this->loadFacts($tenantId);
        
        return array_values(array_filter($facts, function ($fact) use ($criteria) {
            foreach ($criteria as $key => $value) {
                $factValue = $fact[$key] ?? $fact['attributes'][$key] ?? null;
                
                // Support partial matching for strings
                if (is_string($value) && is_string($factValue)) {
                    if (stripos($factValue, $value) === false) {
                        return false;
                    }
                } elseif ($factValue !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * {@inheritdoc}
     */
    public function getAllFacts(string $tenantId, int $limit = 100, int $offset = 0): array
    {
        $this->validateTenantId($tenantId);

        $facts = $this->loadFacts($tenantId);
        
        // Sort by created_at descending
        usort($facts, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        
        return array_slice($facts, $offset, $limit);
    }

    /**
     * {@inheritdoc}
     */
    public function executeQuery(string $tenantId, string $query, array $params = []): array
    {
        $this->validateTenantId($tenantId);

        // Simple query language support for file-based storage
        // Format: "FIND entity WHERE type = 'person' AND attribute.name = 'John'"
        
        $facts = $this->loadFacts($tenantId);
        
        if (stripos($query, 'FIND') === 0) {
            return $this->executeSimpleQuery($facts, $query, $params);
        }
        
        // Default: return all facts
        return $facts;
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): bool
    {
        return is_dir($this->basePath) && is_writable($this->basePath);
    }

    /**
     * Execute a simple query on facts.
     *
     * @param array $facts Facts to query
     * @param string $query Query string
     * @param array $params Query parameters
     * @return array Query results
     */
    private function executeSimpleQuery(array $facts, string $query, array $params): array
    {
        // Basic implementation - can be extended for more complex queries
        preg_match('/WHERE\s+(.+)$/i', $query, $matches);
        
        if (empty($matches[1])) {
            return $facts;
        }
        
        $conditions = $matches[1];
        $criteria = [];
        
        // Parse simple conditions: key = 'value' AND key2 = 'value2'
        preg_match_all("/(\w+(?:\.\w+)?)\s*=\s*'([^']+)'/", $conditions, $condMatches, PREG_SET_ORDER);
        
        foreach ($condMatches as $match) {
            $key = $match[1];
            $value = $params[$match[2]] ?? $match[2];
            
            if (strpos($key, '.') !== false) {
                [$parent, $child] = explode('.', $key);
                $criteria["{$parent}.{$child}"] = $value;
            } else {
                $criteria[$key] = $value;
            }
        }
        
        return array_values(array_filter($facts, function ($fact) use ($criteria) {
            foreach ($criteria as $key => $value) {
                if (strpos($key, '.') !== false) {
                    [$parent, $child] = explode('.', $key);
                    $factValue = $fact[$parent][$child] ?? null;
                } else {
                    $factValue = $fact[$key] ?? null;
                }
                
                if ($factValue !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Get the tenant storage path.
     *
     * @param string $tenantId Tenant ID
     * @return string Tenant path
     */
    private function getTenantPath(string $tenantId): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 
               $this->sanitizePath($tenantId) . DIRECTORY_SEPARATOR . 
               'graph';
    }

    /**
     * Load facts from file.
     *
     * @param string $tenantId Tenant ID
     * @return array Facts array
     */
    private function loadFacts(string $tenantId): array
    {
        $filePath = $this->getTenantPath($tenantId) . DIRECTORY_SEPARATOR . 'facts.json';
        
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Save facts to file.
     *
     * @param string $tenantId Tenant ID
     * @param array $facts Facts to save
     * @return void
     */
    private function saveFacts(string $tenantId, array $facts): void
    {
        $tenantPath = $this->getTenantPath($tenantId);
        $this->ensureDirectory($tenantPath);
        
        $filePath = $tenantPath . DIRECTORY_SEPARATOR . 'facts.json';
        file_put_contents($filePath, json_encode($facts, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Load relationships from file.
     *
     * @param string $tenantId Tenant ID
     * @return array Relationships array
     */
    private function loadRelationships(string $tenantId): array
    {
        $filePath = $this->getTenantPath($tenantId) . DIRECTORY_SEPARATOR . 'relationships.json';
        
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Save relationships to file.
     *
     * @param string $tenantId Tenant ID
     * @param array $relationships Relationships to save
     * @return void
     */
    private function saveRelationships(string $tenantId, array $relationships): void
    {
        $tenantPath = $this->getTenantPath($tenantId);
        $this->ensureDirectory($tenantPath);
        
        $filePath = $tenantPath . DIRECTORY_SEPARATOR . 'relationships.json';
        file_put_contents($filePath, json_encode($relationships, JSON_PRETTY_PRINT), LOCK_EX);
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
     * Sanitize a path component.
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
     * Update entity index for faster lookups.
     *
     * @param string $tenantId Tenant ID
     * @param array $fact Fact to index
     * @return void
     */
    private function updateEntityIndex(string $tenantId, array $fact): void
    {
        $key = "{$tenantId}:{$fact['entity']}";
        $this->entityIndex[$key] = $fact['id'];
    }

    /**
     * Invalidate fact cache.
     *
     * @param string $tenantId Tenant ID
     * @return void
     */
    private function invalidateFactCache(string $tenantId): void
    {
        if ($this->cache) {
            $this->cache->clearPattern("facts_by_type:{$tenantId}:*");
            $this->cache->clearPattern("fact_by_entity:{$tenantId}:*");
        }
    }

    /**
     * Invalidate relationship cache.
     *
     * @param string $tenantId Tenant ID
     * @return void
     */
    private function invalidateRelationshipCache(string $tenantId): void
    {
        if ($this->cache) {
            $this->cache->clearPattern("relationships:{$tenantId}:*");
        }
    }
}
