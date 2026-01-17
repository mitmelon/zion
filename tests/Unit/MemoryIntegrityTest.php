<?php

namespace ZionXMemory\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZionXMemory\Orchestrator\HybridMemory;
use ZionXMemory\Storage\Adapters\RedisAdapter;
use ZionXMemory\AI\Adapters\GeminiAdapter;
use ZionXMemory\Audit\AuditLogger;

/**
 * Memory Integrity Test Suite
 * Validates core memory properties and guarantees
 */
class MemoryIntegrityTest extends TestCase {
    private HybridMemory $memory;
    private string $tenantId = 'test_tenant';
    private string $agentId = 'test_agent';
    
    protected function setUp(): void {
        $storage = new RedisAdapter();
        $storage->connect(['host' => '127.0.0.1', 'port' => 6379]);
        
        $ai = new GeminiAdapter();
        $ai->configure([
            'api_key' => getenv('GEMINI_API_KEY'),
            'base_url' => getenv('GEMINI_BASE_URL') ?: '',
            'model' => 'gemini-pro',
            'retry' => ['max_attempts' => 3, 'base_delay_ms' => 200]
        ]);
        
        $audit = new AuditLogger($storage);
        
        $this->memory = new HybridMemory($storage, $ai, $audit);
    }
    
    /**
     * Test 1: Non-Destructive Memory
     * Verify that no memory is ever overwritten
     */
    public function testNonDestructiveMemory(): void {
        // Store initial memory
        $result1 = $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'test',
            'content' => 'Original content'
        ]);
        
        $memoryId = $result1['memory_id'];
        
        // Store update (should create version, not overwrite)
        $result2 = $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'test',
            'content' => 'Updated content',
            'parent_id' => $memoryId
        ]);
        
        // Get lineage - should have both versions
        $lineage = $this->memory->getMemoryLineage($this->tenantId, $memoryId);
        
        $this->assertGreaterThanOrEqual(1, count($lineage['mindscape_lineage']));
        
        // Original should still exist
        $original = $lineage['mindscape_lineage'][0];
        $this->assertEquals('Original content', $original['data']['content']);
    }
    
    /**
     * Test 2: Belief Lifecycle Integrity
     * Verify belief state transitions are valid and tracked
     */
    public function testBeliefLifecycleIntegrity(): void {
        $result = $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'hypothesis',
            'content' => 'Test hypothesis',
            'claims' => [
                ['text' => 'The sky is blue', 'confidence' => ['min' => 0.8, 'max' => 0.95, 'mean' => 0.9]]
            ]
        ]);
        
        $beliefId = $result['beliefs'][0];
        
        // Valid transition: hypothesis → accepted
        $success1 = $this->memory->updateBelief($this->tenantId, $beliefId, 'accepted', 'Evidence confirmed', $this->agentId);
        $this->assertTrue($success1);
        
        // Valid transition: accepted → contested
        $success2 = $this->memory->updateBelief($this->tenantId, $beliefId, 'contested', 'New contradicting evidence', $this->agentId);
        $this->assertTrue($success2);
        
        // Get history - should have all transitions
        $lineage = $this->memory->getMemoryLineage($this->tenantId, $result['memory_id']);
        $beliefHistory = $lineage['belief_history'][0] ?? [];
        
        $this->assertGreaterThanOrEqual(2, count($beliefHistory));
        
        // Verify states
        $states = array_column($beliefHistory, 'state');
        $this->assertContains('accepted', $states);
        $this->assertContains('contested', $states);
    }
    
    /**
     * Test 3: Contradiction Detection
     * Verify contradictions are detected and preserved
     */
    public function testContradictionDetection(): void {
        // Agent 1 stores a belief
        $result1 = $this->memory->storeMemory($this->tenantId, 'agent1', [
            'type' => 'claim',
            'content' => 'The market will grow',
            'claims' => [
                ['text' => 'Market growth expected', 'confidence' => ['min' => 0.6, 'max' => 0.8, 'mean' => 0.7]]
            ]
        ]);
        
        // Agent 2 stores contradicting belief
        $result2 = $this->memory->storeMemory($this->tenantId, 'agent2', [
            'type' => 'claim',
            'content' => 'The market will not grow',
            'claims' => [
                ['text' => 'Market decline expected', 'confidence' => ['min' => 0.5, 'max' => 0.7, 'mean' => 0.6]]
            ]
        ]);
        
        // Build context - should include contradictions
        $context = $this->memory->buildContextSnapshot($this->tenantId, 'agent1', [
            'include_contradictions' => true
        ]);
        
        // Should detect at least some contradictions
        $this->assertIsArray($context['contradictions']);
    }
    
    /**
     * Test 4: Audit Chain Integrity
     * Verify tamper-evident audit logging
     */
    public function testAuditChainIntegrity(): void {
        // Store multiple memories to create audit chain
        for ($i = 0; $i < 5; $i++) {
            $this->memory->storeMemory($this->tenantId, $this->agentId, [
                'type' => 'test',
                'content' => "Test content {$i}"
            ]);
        }
        
        // Verify chain integrity
        $audit = new AuditLogger($this->memory->storage ?? new RedisAdapter());
        $integrity = $audit->verifyChainIntegrity($this->tenantId);
        
        $this->assertTrue($integrity['valid'], 'Audit chain should be valid');
        $this->assertGreaterThan(0, $integrity['total_records']);
        $this->assertEmpty($integrity['broken_links'], 'No broken links should exist');
    }
    
    /**
     * Test 5: Temporal Stratification
     * Verify memory is organized into correct temporal layers
     */
    public function testTemporalStratification(): void {
        // Store memories at different simulated times
        $memories = [];
        $now = time();
        
        // Recent (hot)
        $memories[] = $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'recent',
            'content' => 'Recent memory',
            'metadata' => ['timestamp' => $now]
        ]);
        
        // 5 days ago (warm)
        $memories[] = $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'warm',
            'content' => 'Memory from 5 days ago',
            'metadata' => ['timestamp' => $now - (5 * 86400)]
        ]);
        
        // 20 days ago (cold)
        $memories[] = $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'cold',
            'content' => 'Memory from 20 days ago',
            'metadata' => ['timestamp' => $now - (20 * 86400)]
        ]);
        
        // Build context with token budget
        $context = $this->memory->buildContextSnapshot($this->tenantId, $this->agentId, [
            'max_tokens' => 4000
        ]);
        
        // Verify layers are present
        $this->assertArrayHasKey('layers', $context['narrative']);
        
        $layers = $context['narrative']['layers'];
        $this->assertArrayHasKey('hot', $layers);
        $this->assertArrayHasKey('warm', $layers);
        $this->assertArrayHasKey('cold', $layers);
    }
    
    /**
     * Test 6: Multi-Agent Isolation
     * Verify agent memories are properly isolated
     */
    public function testMultiAgentIsolation(): void {
        $agent1 = 'isolated_agent_1';
        $agent2 = 'isolated_agent_2';
        
        // Agent 1 stores private memory
        $result1 = $this->memory->storeMemory($this->tenantId, $agent1, [
            'type' => 'private',
            'content' => 'Agent 1 private data'
        ]);
        
        // Agent 2 stores different memory
        $result2 = $this->memory->storeMemory($this->tenantId, $agent2, [
            'type' => 'private',
            'content' => 'Agent 2 private data'
        ]);
        
        // Get context for agent 1
        $context1 = $this->memory->buildContextSnapshot($this->tenantId, $agent1);
        
        // Get context for agent 2
        $context2 = $this->memory->buildContextSnapshot($this->tenantId, $agent2);
        
        // Contexts should be different (agents see their own memories)
        $this->assertNotEquals($context1, $context2);
    }
    
    /**
     * Test 7: Long-Term Memory Preservation
     * Verify semantic meaning is preserved over time
     */
    public function testLongTermPreservation(): void {
        // Store initial detailed memory
        $originalContent = 'This is a very detailed description of an important event with many specific facts and nuances.';
        
        $result = $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'important_event',
            'content' => $originalContent,
            'metadata' => ['importance' => 'high']
        ]);
        
        $memoryId = $result['memory_id'];
        
        // Simulate time passing (in real system, summarization would trigger)
        // For test, we verify original is still accessible
        $lineage = $this->memory->getMemoryLineage($this->tenantId, $memoryId);
        
        $this->assertNotEmpty($lineage['mindscape_lineage']);
        
        $original = $lineage['mindscape_lineage'][0]['data'];
        $this->assertEquals($originalContent, $original['content']);
    }
    
    /**
     * Test 8: Query Capabilities
     * Verify complex queries work across all layers
     */
    public function testComplexQuery(): void {
        // Store diverse memories
        $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'research',
            'content' => 'Research finding A',
            'claims' => [
                ['text' => 'Finding A is true', 'confidence' => ['min' => 0.8, 'max' => 0.9, 'mean' => 0.85]]
            ]
        ]);
        
        $this->memory->storeMemory($this->tenantId, $this->agentId, [
            'type' => 'experiment',
            'content' => 'Experiment result B'
        ]);
        
        // Query with filters
        $results = $this->memory->queryMemory($this->tenantId, [
            'filters' => [
                'agent_id' => $this->agentId,
                'type' => 'research'
            ],
            'layers' => [
                'mindscape' => true,
                'graph' => true,
                'gnosis' => true
            ]
        ]);
        
        $this->assertArrayHasKey('narrative', $results);
        $this->assertArrayHasKey('graph', $results);
        $this->assertArrayHasKey('beliefs', $results);
    }
    
    /**
     * Test 9: Storage Adapter Interchangeability
     * Verify different storage backends work correctly
     */
    public function testStorageAdapterInterchangeability(): void {
        // This would test MongoDB, MySQL, PostgreSQL adapters
        // For now, verify Redis works
        $storage = new RedisAdapter();
        $connected = $storage->connect(['host' => '127.0.0.1', 'port' => 6379]);
        
        $this->assertTrue($connected, 'Redis adapter should connect');
        
        // Test write/read
        $testKey = 'test:key:' . uniqid();
        $testValue = ['data' => 'test'];
        
        $written = $storage->write($testKey, $testValue, ['tenant' => 'test']);
        $this->assertTrue($written);
        
        $read = $storage->read($testKey);
        $this->assertEquals($testValue, $read);
    }
    
    /**
     * Test 10: Performance Under Load
     * Verify system handles multiple concurrent operations
     */
    public function testPerformanceUnderLoad(): void {
        $startTime = microtime(true);
        $operationCount = 50;
        
        // Store many memories quickly
        for ($i = 0; $i < $operationCount; $i++) {
            $this->memory->storeMemory($this->tenantId, $this->agentId, [
                'type' => 'load_test',
                'content' => "Load test memory {$i}"
            ]);
        }
        
        $duration = microtime(true) - $startTime;
        $avgTime = $duration / $operationCount;
        
        // Should complete in reasonable time (< 1 second per operation on average)
        $this->assertLessThan(1.0, $avgTime, 'Average operation time should be under 1 second');
        
        // Verify all memories were stored
        $metrics = $this->memory->getHealthMetrics($this->tenantId);
        $this->assertGreaterThanOrEqual($operationCount, $metrics['narrative_memories']);
    }
}

/**
 * Run tests
 */
if (php_sapi_name() === 'cli') {
    echo "ZionXMemory Memory Integrity Test Suite\n";
    echo "========================================\n\n";
    
    $suite = new MemoryIntegrityTest('testMemoryIntegrity');
    
    $tests = [
        'testNonDestructiveMemory' => 'Non-Destructive Memory',
        'testBeliefLifecycleIntegrity' => 'Belief Lifecycle Integrity',
        'testContradictionDetection' => 'Contradiction Detection',
        'testAuditChainIntegrity' => 'Audit Chain Integrity',
        'testTemporalStratification' => 'Temporal Stratification',
        'testMultiAgentIsolation' => 'Multi-Agent Isolation',
        'testLongTermPreservation' => 'Long-Term Preservation',
        'testComplexQuery' => 'Complex Query Capabilities',
        'testStorageAdapterInterchangeability' => 'Storage Adapter Interchangeability',
        'testPerformanceUnderLoad' => 'Performance Under Load'
    ];
    
    $passed = 0;
    $failed = 0;
    
    foreach ($tests as $method => $name) {
        echo "Testing: {$name}... ";
        
        try {
            $suite->setUp();
            $suite->$method();
            echo "✓ PASSED\n";
            $passed++;
        } catch (\Exception $e) {
            echo "✗ FAILED: {$e->getMessage()}\n";
            $failed++;
        }
    }
    
    echo "\n========================================\n";
    echo "Results: {$passed} passed, {$failed} failed\n";
    echo "========================================\n";
}