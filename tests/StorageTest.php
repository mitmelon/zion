<?php

declare(strict_types=1);

namespace Zion\Memory\Tests;

use PHPUnit\Framework\TestCase;
use Zion\Memory\Storage\InMemoryCache;
use Zion\Memory\Storage\FileStorageAdapter;
use Zion\Memory\Storage\FileGraphAdapter;

/**
 * Unit tests for Storage Adapters
 */
class StorageTest extends TestCase
{
    private string $testStoragePath;
    private string $testGraphPath;

    protected function setUp(): void
    {
        $this->testStoragePath = sys_get_temp_dir() . '/banking_ai_test_storage_' . uniqid();
        $this->testGraphPath = sys_get_temp_dir() . '/banking_ai_test_graph_' . uniqid();
        
        mkdir($this->testStoragePath, 0755, true);
        mkdir($this->testGraphPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testStoragePath);
        $this->removeDirectory($this->testGraphPath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // InMemoryCache Tests
    // =========================================================================

    public function testCacheSetAndGet(): void
    {
        $cache = new InMemoryCache();
        
        $cache->set('test_key', ['data' => 'value'], 3600);
        $result = $cache->get('test_key');
        
        $this->assertEquals(['data' => 'value'], $result);
    }

    public function testCacheHas(): void
    {
        $cache = new InMemoryCache();
        
        $this->assertFalse($cache->has('nonexistent'));
        
        $cache->set('exists', 'value', 3600);
        $this->assertTrue($cache->has('exists'));
    }

    public function testCacheDelete(): void
    {
        $cache = new InMemoryCache();
        
        $cache->set('to_delete', 'value', 3600);
        $this->assertTrue($cache->has('to_delete'));
        
        $cache->delete('to_delete');
        $this->assertFalse($cache->has('to_delete'));
    }

    public function testCacheClear(): void
    {
        $cache = new InMemoryCache();
        
        $cache->set('key1', 'value1', 3600);
        $cache->set('key2', 'value2', 3600);
        
        $cache->clear();
        
        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
    }

    public function testCacheExpiration(): void
    {
        $cache = new InMemoryCache();
        
        // Set with very short TTL
        $cache->set('expiring', 'value', 1);
        
        // Wait for expiration
        sleep(2);
        
        $this->assertNull($cache->get('expiring'));
    }

    public function testCacheGetDefault(): void
    {
        $cache = new InMemoryCache();
        
        $result = $cache->get('nonexistent', 'default_value');
        
        $this->assertEquals('default_value', $result);
    }

    // =========================================================================
    // FileStorageAdapter Tests
    // =========================================================================

    public function testFileStorageAddMessage(): void
    {
        $cache = new InMemoryCache();
        $storage = new FileStorageAdapter($this->testStoragePath, $cache);
        
        $storage->addMessage('tenant1', 'session1', 'user', 'Hello world');
        
        $messages = $storage->getMessages('tenant1', 'session1');
        
        $this->assertCount(1, $messages);
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('Hello world', $messages[0]['content']);
    }

    public function testFileStorageMultipleMessages(): void
    {
        $cache = new InMemoryCache();
        $storage = new FileStorageAdapter($this->testStoragePath, $cache);
        
        $storage->addMessage('tenant1', 'session1', 'user', 'Message 1');
        $storage->addMessage('tenant1', 'session1', 'assistant', 'Message 2');
        $storage->addMessage('tenant1', 'session1', 'user', 'Message 3');
        
        $messages = $storage->getMessages('tenant1', 'session1');
        
        $this->assertCount(3, $messages);
    }

    public function testFileStorageTenantIsolation(): void
    {
        $cache = new InMemoryCache();
        $storage = new FileStorageAdapter($this->testStoragePath, $cache);
        
        $storage->addMessage('tenant1', 'session1', 'user', 'Tenant 1 message');
        $storage->addMessage('tenant2', 'session1', 'user', 'Tenant 2 message');
        
        $tenant1Messages = $storage->getMessages('tenant1', 'session1');
        $tenant2Messages = $storage->getMessages('tenant2', 'session1');
        
        $this->assertCount(1, $tenant1Messages);
        $this->assertCount(1, $tenant2Messages);
        $this->assertEquals('Tenant 1 message', $tenant1Messages[0]['content']);
        $this->assertEquals('Tenant 2 message', $tenant2Messages[0]['content']);
    }

    public function testFileStorageLimitedMessages(): void
    {
        $cache = new InMemoryCache();
        $storage = new FileStorageAdapter($this->testStoragePath, $cache);
        
        for ($i = 1; $i <= 10; $i++) {
            $storage->addMessage('tenant1', 'session1', 'user', "Message $i");
        }
        
        $messages = $storage->getMessages('tenant1', 'session1', 5);
        
        $this->assertCount(5, $messages);
        $this->assertEquals('Message 6', $messages[0]['content']); // Should get last 5
    }

    public function testFileStorageSummary(): void
    {
        $cache = new InMemoryCache();
        $storage = new FileStorageAdapter($this->testStoragePath, $cache);
        
        $summary = [
            'content' => 'This is a test summary',
            'timestamp' => time(),
        ];
        
        $storage->storeSummary('tenant1', 'session1', $summary);
        $retrieved = $storage->getSummary('tenant1', 'session1');
        
        $this->assertEquals('This is a test summary', $retrieved['content']);
    }

    // =========================================================================
    // FileGraphAdapter Tests
    // =========================================================================

    public function testGraphAddFact(): void
    {
        $graph = new FileGraphAdapter($this->testGraphPath);
        
        $fact = [
            'id' => 'fact_1',
            'subject' => 'John',
            'relation' => 'works_at',
            'object' => 'TechCorp',
            'confidence' => 0.95,
        ];
        
        $graph->addFact('tenant1', $fact);
        $facts = $graph->getFacts('tenant1');
        
        $this->assertCount(1, $facts);
        $this->assertEquals('John', $facts[0]['subject']);
        $this->assertEquals('works_at', $facts[0]['relation']);
        $this->assertEquals('TechCorp', $facts[0]['object']);
    }

    public function testGraphFindRelated(): void
    {
        $graph = new FileGraphAdapter($this->testGraphPath);
        
        $graph->addFact('tenant1', [
            'id' => 'fact_1',
            'subject' => 'John',
            'relation' => 'works_at',
            'object' => 'TechCorp',
        ]);
        
        $graph->addFact('tenant1', [
            'id' => 'fact_2',
            'subject' => 'John',
            'relation' => 'lives_in',
            'object' => 'New York',
        ]);
        
        $related = $graph->findRelated('tenant1', 'John', 'works_at');
        
        $this->assertContains('TechCorp', $related);
        $this->assertNotContains('New York', $related);
    }

    public function testGraphGetFactsByEntity(): void
    {
        $graph = new FileGraphAdapter($this->testGraphPath);
        
        $graph->addFact('tenant1', [
            'id' => 'fact_1',
            'subject' => 'John',
            'relation' => 'works_at',
            'object' => 'TechCorp',
        ]);
        
        $graph->addFact('tenant1', [
            'id' => 'fact_2',
            'subject' => 'Jane',
            'relation' => 'works_at',
            'object' => 'TechCorp',
        ]);
        
        $johnFacts = $graph->getFactsByEntity('tenant1', 'John');
        
        $this->assertCount(1, $johnFacts);
        $this->assertEquals('John', $johnFacts[0]['subject']);
    }

    public function testGraphTenantIsolation(): void
    {
        $graph = new FileGraphAdapter($this->testGraphPath);
        
        $graph->addFact('tenant1', [
            'id' => 'fact_1',
            'subject' => 'Entity1',
            'relation' => 'rel',
            'object' => 'Value1',
        ]);
        
        $graph->addFact('tenant2', [
            'id' => 'fact_2',
            'subject' => 'Entity2',
            'relation' => 'rel',
            'object' => 'Value2',
        ]);
        
        $tenant1Facts = $graph->getFacts('tenant1');
        $tenant2Facts = $graph->getFacts('tenant2');
        
        $this->assertCount(1, $tenant1Facts);
        $this->assertCount(1, $tenant2Facts);
        $this->assertEquals('Entity1', $tenant1Facts[0]['subject']);
        $this->assertEquals('Entity2', $tenant2Facts[0]['subject']);
    }

    public function testGraphTraversal(): void
    {
        $graph = new FileGraphAdapter($this->testGraphPath);
        
        // Create a chain: A -> B -> C
        $graph->addFact('tenant1', [
            'id' => 'fact_1',
            'subject' => 'A',
            'relation' => 'connects_to',
            'object' => 'B',
        ]);
        
        $graph->addFact('tenant1', [
            'id' => 'fact_2',
            'subject' => 'B',
            'relation' => 'connects_to',
            'object' => 'C',
        ]);
        
        $path = $graph->traverseGraph('tenant1', 'A', 'C');
        
        $this->assertContains('A', $path);
        $this->assertContains('B', $path);
        $this->assertContains('C', $path);
    }
}
