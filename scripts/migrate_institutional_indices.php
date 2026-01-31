<?php
// scripts/migrate_institutional_indices.php

require_once __DIR__ . '/../vendor/autoload.php';

// In a real app, you would retrieve the configured storage adapter from the container.
// Here we attempt to instantiate one based on assumptions or configuration.

use ZionXMemory\Storage\Adapters\RedisAdapter;
use ZionXMemory\Storage\Adapters\MySQLAdapter;
use ZionXMemory\Storage\Adapters\PostgresAdapter;
use ZionXMemory\Storage\Adapters\MongoAdapter;

function getStorage() {
    // Simple factory for migration script
    // Adjust configuration as needed for your environment

    $driver = getenv('STORAGE_DRIVER') ?: 'redis';

    switch ($driver) {
        case 'mysql':
            $adapter = new MySQLAdapter();
            $adapter->connect([
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'database' => getenv('DB_NAME') ?: 'zionxmemory',
                'username' => getenv('DB_USER') ?: 'root',
                'password' => getenv('DB_PASS') ?: ''
            ]);
            return $adapter;
        case 'postgres':
            $adapter = new PostgresAdapter();
            $adapter->connect([
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'database' => getenv('DB_NAME') ?: 'zionxmemory',
                'username' => getenv('DB_USER') ?: 'postgres',
                'password' => getenv('DB_PASS') ?: ''
            ]);
            return $adapter;
        case 'mongo':
            $adapter = new MongoAdapter();
            $adapter->connect([
                'uri' => getenv('MONGO_URI') ?: 'mongodb://localhost:27017',
                'database' => getenv('MONGO_DB') ?: 'zionxmemory'
            ]);
            return $adapter;
        case 'redis':
        default:
            $adapter = new RedisAdapter();
            $adapter->connect([
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => getenv('REDIS_PORT') ?: 6379,
                'password' => getenv('REDIS_PASSWORD') ?: null
            ]);
            return $adapter;
    }
}

if ($argc < 2) {
    die("Usage: php migrate_institutional_indices.php <tenantId>\n");
}

$tenantId = $argv[1];
echo "Migrating tenant: $tenantId\n";

try {
    $storage = getStorage();
} catch (\Throwable $e) {
    die("Failed to connect to storage: " . $e->getMessage() . "\n");
}

// 1. Scan all items for tenant
echo "Scanning items...\n";
$pattern = "institutional:{$tenantId}:*";
$items = $storage->query(['pattern' => $pattern]);

echo "Found " . count($items) . " items. Indexing...\n";

$indexed = 0;
foreach ($items as $item) {
    $id = $item['id'] ?? null;
    $timestamp = $item['promoted_at'] ?? $item['timestamp'] ?? 0;

    if ($id && $timestamp > 0) {
        $dayKey = date('Y-m-d', $timestamp);
        $indexKey = "institutional_index:{$tenantId}:daily:{$dayKey}";
        $success = $storage->addToSet($indexKey, $id, ['tenant' => $tenantId, 'type' => 'institutional_index']);
        if ($success) {
            $indexed++;
        }
    }
}

// 2. Mark migration as complete
$flagKey = "institutional_index:migration_complete:{$tenantId}";
$storage->write($flagKey, ['complete' => true, 'at' => time()], ['tenant' => $tenantId, 'type' => 'migration_flag']);

echo "Migration complete. Indexed $indexed items.\n";
echo "Flag set: $flagKey\n";
