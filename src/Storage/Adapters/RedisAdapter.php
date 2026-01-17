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
    
    public function query(array $criteria): array {
        if (!$this->connected) {
            return [];
        }
        
        $pattern = $criteria['pattern'] ?? '*';
        $keys = $this->redis->keys($pattern);
        
        $results = [];
        foreach ($keys as $key) {
            $value = $this->read($key);
            if ($value !== null) {
                $results[] = $value;
            }
        }
        
        return $results;
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
}