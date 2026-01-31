<?php
namespace ZionXMemory\Storage\Adapters;

use ZionXMemory\Contracts\StorageAdapterInterface;

/**
 * RedisAdapter - Fast in-memory storage
 * Primary for hot data, caching, and serving
 * 
 * @package ZionXMemory\Storage\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class RedisAdapter implements StorageAdapterInterface {
    private $redis;
    private bool $connected = false;
    
    public function connect(array $config): bool {
        $this->redis = new \Redis();
        
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $password = $config['password'] ?? null;
        
        try {
            $this->connected = $this->redis->connect($host, $port);
            
            if ($password) {
                $this->redis->auth($password);
            }
            
            return $this->connected;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function write(string $key, mixed $value, array $metadata): bool {
        if (!$this->connected) {
            return false;
        }
        
        $data = [
            'value' => $value,
            'metadata' => $metadata,
            'written_at' => time()
        ];
        
        return $this->redis->set($key, json_encode($data));
    }

    public function writeMulti(array $items): bool {
        if (!$this->connected) {
            return false;
        }

        $dataMap = [];
        foreach ($items as $item) {
            $data = [
                'value' => $item['value'],
                'metadata' => $item['metadata'],
                'written_at' => time()
            ];
            $dataMap[$item['key']] = json_encode($data);
        }

        try {
            return $this->redis->mset($dataMap);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function read(string $key): mixed {
        if (!$this->connected) {
            return null;
        }
        
        $data = $this->redis->get($key);
        
        if ($data === false) {
            return null;
        }
        
        $decoded = json_decode($data, true);
        return $decoded['value'] ?? null;
    }

    public function readMulti(array $keys): array {
        if (!$this->connected || empty($keys)) {
            return [];
        }

        $values = $this->redis->mget($keys);

        if ($values === false) {
            return [];
        }

        $results = [];
        foreach ($keys as $i => $key) {
            $val = $values[$i];
            if ($val !== false && $val !== null) {
                $decoded = json_decode($val, true);
                if (isset($decoded['value'])) {
                    $results[$key] = $decoded['value'];
                }
            }
        }

        return $results;
    }
    
    public function query(array $criteria): array {
        if (!$this->connected) {
            return [];
        }
        
        $pattern = $criteria['pattern'] ?? '*';
        $filters = $criteria['filter'] ?? [];

        $results = [];
        $iterator = null;
        $seen = [];

        do {
            // Use SCAN instead of KEYS to avoid blocking the Redis server
            $keys = $this->redis->scan($iterator, $pattern, 100);

            if ($keys === false) {
                break;
            }

            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $value = $this->read($key);
                if ($value !== null) {
                    // Check filters
                    $match = true;
                    if (!empty($filters)) {
                        foreach ($filters as $filter) {
                            $field = $filter['field'];
                            $op = $filter['operator'];
                            $val = $filter['value'];

                            $fieldVal = null;
                            if (is_array($field)) {
                                foreach ($field as $f) {
                                    if (isset($value[$f])) {
                                        $fieldVal = $value[$f];
                                        break;
                                    }
                                }
                            } else {
                                $fieldVal = $value[$field] ?? null;
                            }

                            if ($fieldVal === null) {
                                $match = false;
                                break;
                            }

                            $isMatch = match($op) {
                                '>=' => $fieldVal >= $val,
                                '<=' => $fieldVal <= $val,
                                '>' => $fieldVal > $val,
                                '<' => $fieldVal < $val,
                                '=' => $fieldVal == $val,
                                '!=' => $fieldVal != $val,
                                default => false
                            };

                            if (!$isMatch) {
                                $match = false;
                                break;
                            }
                        }
                    }

                    if ($match) {
                        $results[] = $value;
                    }
                }
            }
        } while ($iterator > 0);
        
        return $results;
    }

    public function count(array $criteria): int {
        if (!$this->connected) {
            return 0;
        }

        $pattern = $criteria['pattern'] ?? '*';
        $iterator = null;
        $seen = [];
        $count = 0;

        do {
            // Use SCAN with a larger batch size for counting
            $keys = $this->redis->scan($iterator, $pattern, 1000);

            if ($keys === false) {
                break;
            }

            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $count++;
            }
        } while ($iterator > 0);

        return $count;
    }
    
    public function count(array $criteria): int {
        if (!$this->connected) {
            return 0;
        }

        $pattern = $criteria['pattern'] ?? '*';
        $count = 0;
        $iterator = null;
        $seen = [];

        do {
            // Use SCAN instead of KEYS to avoid blocking the Redis server
            // Only counting keys, no retrieval of values
            $keys = $this->redis->scan($iterator, $pattern, 1000);

            if ($keys === false) {
                break;
            }

            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $count++;
            }
        } while ($iterator > 0);

        return $count;
    }

    public function exists(string $key): bool {
        if (!$this->connected) {
            return false;
        }
        
        return $this->redis->exists($key) > 0;
    }
    
    public function getMetadata(string $key): array {
        if (!$this->connected) {
            return [];
        }
        
        $data = $this->redis->get($key);
        
        if ($data === false) {
            return [];
        }
        
        $decoded = json_decode($data, true);
        return $decoded['metadata'] ?? [];
    }

    public function addToSet(string $key, string $value, array $metadata = []): bool {
        if (!$this->connected) {
            return false;
        }
        try {
            // Returns int (number of elements added) or false
            return $this->redis->sAdd($key, $value) !== false;
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'WRONGTYPE') !== false) {
                // Migration: Convert JSON String to Set
                $oldData = $this->redis->get($key);
                if ($oldData !== false) {
                    $decoded = json_decode($oldData, true);
                    $items = $decoded['value'] ?? [];

                    $this->redis->del($key);
                    if (is_array($items) && !empty($items)) {
                        foreach ($items as $item) {
                            $this->redis->sAdd($key, $item);
                        }
                    }
                    return $this->redis->sAdd($key, $value) !== false;
                }
                // If old data invalid, just overwrite
                $this->redis->del($key);
                return $this->redis->sAdd($key, $value) !== false;
            }
            return false;
        }
    }

    public function removeFromSet(string $key, string $value, array $metadata = []): bool {
        if (!$this->connected) {
            return false;
        }
        try {
            return $this->redis->sRem($key, $value) !== false;
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'WRONGTYPE') !== false) {
                // Migration
                $oldData = $this->redis->get($key);
                if ($oldData !== false) {
                    $decoded = json_decode($oldData, true);
                    $items = $decoded['value'] ?? [];

                    $this->redis->del($key);
                    if (is_array($items) && !empty($items)) {
                        foreach ($items as $item) {
                            if ($item !== $value) {
                                $this->redis->sAdd($key, $item);
                            }
                        }
                    }
                    return true;
                }
                return true;
            }
            return false;
        }
    }

    public function getSetMembers(string $key): array {
        if (!$this->connected) {
            return [];
        }
        try {
            return $this->redis->sMembers($key);
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'WRONGTYPE') !== false) {
                // Fallback: Read as JSON String
                $oldData = $this->redis->get($key);
                if ($oldData !== false) {
                    $decoded = json_decode($oldData, true);
                    return $decoded['value'] ?? [];
                }
            }
            return [];
        }
    }

    public function isSetMember(string $key, string $value): bool {
        if (!$this->connected) {
            return false;
        }
        try {
            return $this->redis->sIsMember($key, $value);
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'WRONGTYPE') !== false) {
                // Fallback: Read as JSON String
                $oldData = $this->redis->get($key);
                if ($oldData !== false) {
                    $decoded = json_decode($oldData, true);
                    $items = $decoded['value'] ?? [];
                    return is_array($items) && in_array($value, $items);
                }
            }
            return false;
        }
    }

    public function getSetCount(string $key): int {
        if (!$this->connected) {
            return 0;
        }
        try {
            return $this->redis->sCard($key);
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'WRONGTYPE') !== false) {
                // Fallback: Read as JSON String
                $oldData = $this->redis->get($key);
                if ($oldData !== false) {
                    $decoded = json_decode($oldData, true);
                    $items = $decoded['value'] ?? [];
                    return is_array($items) ? count($items) : 0;
                }
            }
            return 0;
        }
    }
}