<?php
namespace ZionXMemory\Storage\Adapters;

use ZionXMemory\Contracts\StorageAdapterInterface;

/**
 * MongoAdapter - Document storage for complex data
 * 
 * @package ZionXMemory\Storage\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class MongoAdapter implements StorageAdapterInterface {
    private $client;
    private $database;
    private $collection;
    private bool $connected = false;
    
    public function connect(array $config): bool {
        try {
            $uri = $config['uri'] ?? 'mongodb://localhost:27017';
            $this->client = new \MongoDB\Client($uri);
            
            $dbName = $config['database'] ?? 'zionxmemory';
            $this->database = $this->client->selectDatabase($dbName);
            
            $collectionName = $config['collection'] ?? 'memories';
            $this->collection = $this->database->selectCollection($collectionName);
            
            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function write(string $key, mixed $value, array $metadata): bool {
        if (!$this->connected) {
            return false;
        }
        
        try {
            $document = [
                '_id' => $key,
                'value' => $value,
                'metadata' => $metadata,
                'written_at' => time()
            ];
            
            $this->collection->replaceOne(
                ['_id' => $key],
                $document,
                ['upsert' => true]
            );
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function writeMulti(array $items): bool {
        if (!$this->connected) {
            return false;
        }

        try {
            $operations = [];
            foreach ($items as $item) {
                $document = [
                    '_id' => $item['key'],
                    'value' => $item['value'],
                    'metadata' => $item['metadata'],
                    'written_at' => time()
                ];

                $operations[] = [
                    'replaceOne' => [
                        ['_id' => $item['key']],
                        $document,
                        ['upsert' => true]
                    ]
                ];
            }

            if (!empty($operations)) {
                $this->collection->bulkWrite($operations);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function read(string $key): mixed {
        if (!$this->connected) {
            return null;
        }
        
        try {
            $document = $this->collection->findOne(['_id' => $key]);
            return $document ? $document['value'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    public function query(array $criteria): array {
        if (!$this->connected) {
            return [];
        }
        
        try {
            $filter = $this->buildMongoFilter($criteria);
            $cursor = $this->collection->find($filter);
            
            $results = [];
            foreach ($cursor as $document) {
                $results[] = $document['value'];
            }
            
            return $results;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function exists(string $key): bool {
        if (!$this->connected) {
            return false;
        }
        
        return $this->collection->countDocuments(['_id' => $key]) > 0;
    }
    
    public function getMetadata(string $key): array {
        if (!$this->connected) {
            return [];
        }
        
        $document = $this->collection->findOne(['_id' => $key]);
        return $document ? $document['metadata'] : [];
    }
    
    private function buildMongoFilter(array $criteria): array {
        $filter = [];
        
        if (isset($criteria['pattern'])) {
            $pattern = str_replace('*', '.*', $criteria['pattern']);
            $filter['_id'] = ['$regex' => $pattern];
        }
        
        if (isset($criteria['metadata'])) {
            foreach ($criteria['metadata'] as $key => $value) {
                $filter["metadata.{$key}"] = $value;
            }
        }
        
        return $filter;
    }
}