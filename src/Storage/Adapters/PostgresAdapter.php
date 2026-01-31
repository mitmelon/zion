<?php
namespace ZionXMemory\Storage\Adapters;

use ZionXMemory\Contracts\StorageAdapterInterface;

/**
 * PostgresAdapter - Advanced relational storage
 * 
 * @package ZionXMemory\Storage\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class PostgresAdapter implements StorageAdapterInterface {
    private $connection;
    private bool $connected = false;
    private string $tableName = 'zionx_memory';
    
    public function connect(array $config): bool {
        try {
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 5432;
            $database = $config['database'] ?? 'zionxmemory';
            $username = $config['username'] ?? 'postgres';
            $password = $config['password'] ?? '';
            
            $this->connection = new \PDO(
                "pgsql:host={$host};port={$port};dbname={$database}",
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
                "INSERT INTO {$this->tableName} (key, value, metadata, written_at) 
                 VALUES (:key, :value, :metadata, :written_at)
                 ON CONFLICT (key) DO UPDATE SET 
                 value = EXCLUDED.value, 
                 metadata = EXCLUDED.metadata,
                 written_at = EXCLUDED.written_at"
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

    public function writeMulti(array $items): bool {
        if (!$this->connected) {
            return false;
        }

        try {
            $this->connection->beginTransaction();
            $stmt = $this->connection->prepare(
                "INSERT INTO {$this->tableName} (key, value, metadata, written_at)
                 VALUES (:key, :value, :metadata, :written_at)
                 ON CONFLICT (key) DO UPDATE SET
                 value = EXCLUDED.value,
                 metadata = EXCLUDED.metadata,
                 written_at = EXCLUDED.written_at"
            );

            foreach ($items as $item) {
                $stmt->execute([
                    'key' => $item['key'],
                    'value' => json_encode($item['value']),
                    'metadata' => json_encode($item['metadata']),
                    'written_at' => time()
                ]);
            }

            $this->connection->commit();
            return true;
        } catch (\PDOException $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            return false;
        }
    }
    
    public function read(string $key): mixed {
        if (!$this->connected) {
            return null;
        }
        
        try {
            $stmt = $this->connection->prepare(
                "SELECT value FROM {$this->tableName} WHERE key = :key"
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
                "SELECT key, value FROM {$this->tableName} WHERE key IN ($placeholders)"
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
                $where[] = "key LIKE :pattern";
                $params['pattern'] = $pattern;
            }
            
            $sql = "SELECT value FROM {$this->tableName}";
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

    public function count(array $criteria): int {
        if (!$this->connected) {
            return 0;
        }

        try {
            $where = [];
            $params = [];

            if (isset($criteria['pattern'])) {
                $pattern = str_replace('*', '%', $criteria['pattern']);
                $where[] = "key LIKE :pattern";
                $params['pattern'] = $pattern;
            }

            $sql = "SELECT COUNT(*) FROM {$this->tableName}";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }
    
    public function exists(string $key): bool {
        if (!$this->connected) {
            return false;
        }
        
        $stmt = $this->connection->prepare(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE key = :key"
        );
        $stmt->execute(['key' => $key]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    public function getMetadata(string $key): array {
        if (!$this->connected) {
            return [];
        }
        
        $stmt = $this->connection->prepare(
            "SELECT metadata FROM {$this->tableName} WHERE key = :key"
        );
        $stmt->execute(['key' => $key]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? json_decode($row['metadata'], true) : [];
    }
    
    private function createTableIfNotExists(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
            key VARCHAR(512) PRIMARY KEY,
            value JSONB,
            metadata JSONB,
            written_at BIGINT
        )";
        
        $this->connection->exec($sql);
        
        $this->connection->exec(
            "CREATE INDEX IF NOT EXISTS idx_{$this->tableName}_written_at 
             ON {$this->tableName} (written_at)"
        );
    }

    public function addToSet(string $key, string $value, array $metadata = []): bool {
        $current = $this->read($key) ?? [];
        if (!is_array($current)) {
            $current = [];
        }

        if (!in_array($value, $current)) {
            $current[] = $value;
            $existingMeta = $this->getMetadata($key);
            $newMeta = array_merge($existingMeta, $metadata);
            return $this->write($key, $current, $newMeta);
        }
        return true;
    }

    public function removeFromSet(string $key, string $value, array $metadata = []): bool {
        $current = $this->read($key) ?? [];
        if (!is_array($current)) {
            return false;
        }

        $keyIndex = array_search($value, $current);
        if ($keyIndex !== false) {
            array_splice($current, $keyIndex, 1);
            $existingMeta = $this->getMetadata($key);
            $newMeta = array_merge($existingMeta, $metadata);
            return $this->write($key, $current, $newMeta);
        }
        return true;
    }

    public function getSetMembers(string $key): array {
        $current = $this->read($key);
        return is_array($current) ? $current : [];
    }

    public function isSetMember(string $key, string $value): bool {
        $current = $this->read($key);
        if (!is_array($current)) {
            return false;
        }
        return in_array($value, $current);
    }
}