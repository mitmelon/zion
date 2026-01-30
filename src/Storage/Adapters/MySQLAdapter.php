<?php
namespace ZionXMemory\Storage\Adapters;

use ZionXMemory\Contracts\StorageAdapterInterface;

/**
 * MySQLAdapter - Relational storage with transactions
 * 
 * @package ZionXMemory\Storage\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class MySQLAdapter implements StorageAdapterInterface {
    private $connection;
    private bool $connected = false;
    private string $tableName = 'zionx_memory';
    
    public function connect(array $config): bool {
        try {
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 3306;
            $database = $config['database'] ?? 'zionxmemory';
            $username = $config['username'] ?? 'root';
            $password = $config['password'] ?? '';
            
            $this->connection = new \PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            
            $this->createTableIfNotExists();
            $this->connected = true;
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    public function write(string $key, mixed $value, array $metadata): bool {
        if (!$this->connected) {
            return false;
        }
        
        try {
            $stmt = $this->connection->prepare(
                "INSERT INTO {$this->tableName} (`key`, `value`, `metadata`, `written_at`) 
                 VALUES (:key, :value, :metadata, :written_at)
                 ON DUPLICATE KEY UPDATE 
                 `value` = VALUES(`value`), 
                 `metadata` = VALUES(`metadata`),
                 `written_at` = VALUES(`written_at`)"
            );
            
            return $stmt->execute([
                'key' => $key,
                'value' => json_encode($value),
                'metadata' => json_encode($metadata),
                'written_at' => time()
            ]);
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    public function read(string $key): mixed {
        if (!$this->connected) {
            return null;
        }
        
        try {
            $stmt = $this->connection->prepare(
                "SELECT `value` FROM {$this->tableName} WHERE `key` = :key"
            );
            $stmt->execute(['key' => $key]);
            
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? json_decode($row['value'], true) : null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    public function readMulti(array $keys): array {
        if (!$this->connected || empty($keys)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->connection->prepare(
                "SELECT `key`, `value` FROM {$this->tableName} WHERE `key` IN ($placeholders)"
            );
            $stmt->execute(array_values($keys));

            $results = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $results[$row['key']] = json_decode($row['value'], true);
            }

            return $results;
        } catch (\PDOException $e) {
            return [];
        }
    }
    
    public function query(array $criteria): array {
        if (!$this->connected) {
            return [];
        }
        
        try {
            $where = [];
            $params = [];
            
            if (isset($criteria['pattern'])) {
                $pattern = str_replace('*', '%', $criteria['pattern']);
                $where[] = "`key` LIKE :pattern";
                $params['pattern'] = $pattern;
            }
            
            $sql = "SELECT `value` FROM {$this->tableName}";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $results[] = json_decode($row['value'], true);
            }
            
            return $results;
        } catch (\PDOException $e) {
            return [];
        }
    }
    
    public function exists(string $key): bool {
        if (!$this->connected) {
            return false;
        }
        
        $stmt = $this->connection->prepare(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE `key` = :key"
        );
        $stmt->execute(['key' => $key]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    public function getMetadata(string $key): array {
        if (!$this->connected) {
            return [];
        }
        
        $stmt = $this->connection->prepare(
            "SELECT `metadata` FROM {$this->tableName} WHERE `key` = :key"
        );
        $stmt->execute(['key' => $key]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? json_decode($row['metadata'], true) : [];
    }
    
    private function createTableIfNotExists(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
            `key` VARCHAR(512) PRIMARY KEY,
            `value` LONGTEXT,
            `metadata` JSON,
            `written_at` BIGINT,
            INDEX idx_written_at (`written_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->connection->exec($sql);
    }
}