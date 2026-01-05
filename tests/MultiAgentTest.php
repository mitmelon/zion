<?php

declare(strict_types=1);

namespace Zion\Memory\Tests;

use PHPUnit\Framework\TestCase;
use Zion\Memory\Agents\BaseAgent;
use Zion\Memory\Agents\MultiAgentCoordinator;
use Zion\Memory\Contracts\AgentInterface;
use Zion\Memory\Contracts\AIProviderInterface;
use Zion\Memory\Validation\ConflictResolver;
use Zion\Memory\Validation\ConflictResolutionStrategy;

/**
 * Unit tests for Multi-Agent System
 */
class MultiAgentTest extends TestCase
{
    // =========================================================================
    // Test Agent Implementation
    // =========================================================================

    private function createTestAgent(
        string $id,
        string $type,
        int $priority,
        array $response
    ): AgentInterface {
        return new class($id, $type, $priority, $response) implements AgentInterface {
            private string $id;
            private string $type;
            private int $priority;
            private array $response;

            public function __construct(string $id, string $type, int $priority, array $response)
            {
                $this->id = $id;
                $this->type = $type;
                $this->priority = $priority;
                $this->response = $response;
            }

            public function process(array $context): array
            {
                return array_merge($this->response, [
                    'agent_id' => $this->id,
                    'agent_type' => $this->type,
                ]);
            }

            public function getAgentId(): string
            {
                return $this->id;
            }

            public function getAgentType(): string
            {
                return $this->type;
            }

            public function getPriority(): int
            {
                return $this->priority;
            }
        };
    }

    // =========================================================================
    // BaseAgent Tests
    // =========================================================================

    public function testBaseAgentProperties(): void
    {
        $mockAI = $this->createMock(AIProviderInterface::class);
        
        $agent = new class($mockAI) extends BaseAgent {
            public function __construct($aiProvider)
            {
                parent::__construct('test_agent', 'test_type', $aiProvider, 75);
            }

            public function process(array $context): array
            {
                return ['result' => 'processed'];
            }
        };
        
        $this->assertEquals('test_agent', $agent->getAgentId());
        $this->assertEquals('test_type', $agent->getAgentType());
        $this->assertEquals(75, $agent->getPriority());
    }

    // =========================================================================
    // MultiAgentCoordinator Tests
    // =========================================================================

    public function testCoordinatorProcessAllAgents(): void
    {
        $agent1 = $this->createTestAgent('agent_1', 'type_a', 100, ['analysis' => 'Result 1']);
        $agent2 = $this->createTestAgent('agent_2', 'type_b', 90, ['analysis' => 'Result 2']);
        $agent3 = $this->createTestAgent('agent_3', 'type_c', 80, ['analysis' => 'Result 3']);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([$agent1, $agent2, $agent3], $resolver);
        
        $context = ['query' => 'Test context'];
        $results = $coordinator->process($context);
        
        $this->assertCount(3, $results);
        $this->assertArrayHasKey('agent_1', $results);
        $this->assertArrayHasKey('agent_2', $results);
        $this->assertArrayHasKey('agent_3', $results);
    }

    public function testCoordinatorAgentPrioritySorting(): void
    {
        $lowPriority = $this->createTestAgent('low', 'type', 10, []);
        $highPriority = $this->createTestAgent('high', 'type', 100, []);
        $medPriority = $this->createTestAgent('med', 'type', 50, []);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::PRIORITY_AGENT);
        $coordinator = new MultiAgentCoordinator(
            [$lowPriority, $highPriority, $medPriority],
            $resolver
        );
        
        // Coordinator should process all agents
        $results = $coordinator->process([]);
        
        $this->assertCount(3, $results);
    }

    public function testCoordinatorConsolidatedResponse(): void
    {
        $agent1 = $this->createTestAgent('agent_1', 'risk', 100, [
            'risk_score' => 75,
            'flags' => ['high_value'],
        ]);
        $agent2 = $this->createTestAgent('agent_2', 'compliance', 90, [
            'compliant' => true,
            'checks_passed' => ['kyc', 'aml'],
        ]);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([$agent1, $agent2], $resolver);
        
        $results = $coordinator->process([]);
        $consolidated = $coordinator->consolidateResponses($results);
        
        $this->assertIsArray($consolidated);
        $this->assertArrayHasKey('agent_responses', $consolidated);
        $this->assertCount(2, $consolidated['agent_responses']);
    }

    public function testCoordinatorEmptyAgentList(): void
    {
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([], $resolver);
        
        $results = $coordinator->process([]);
        
        $this->assertEmpty($results);
    }

    public function testCoordinatorConflictResolution(): void
    {
        $agent1 = $this->createTestAgent('agent_1', 'analysis', 100, [
            'customer_segment' => 'premium',
            'timestamp' => time() - 3600,
        ]);
        $agent2 = $this->createTestAgent('agent_2', 'analysis', 90, [
            'customer_segment' => 'standard',
            'timestamp' => time(),
        ]);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::PRIORITY_AGENT);
        $coordinator = new MultiAgentCoordinator([$agent1, $agent2], $resolver);
        
        $results = $coordinator->process([]);
        
        // Both agents should have processed
        $this->assertCount(2, $results);
        
        // With PRIORITY_AGENT, higher priority agent's response should be preferred in conflicts
        $consolidated = $coordinator->consolidateResponses($results);
        $this->assertArrayHasKey('agent_responses', $consolidated);
    }

    public function testCoordinatorContextPassthrough(): void
    {
        $contextReceived = null;
        
        $agent = new class($contextReceived) implements AgentInterface {
            private $contextRef;
            
            public function __construct(&$contextRef)
            {
                $this->contextRef = &$contextRef;
            }
            
            public function process(array $context): array
            {
                $this->contextRef = $context;
                return ['processed' => true];
            }
            
            public function getAgentId(): string
            {
                return 'context_test_agent';
            }
            
            public function getAgentType(): string
            {
                return 'test';
            }
            
            public function getPriority(): int
            {
                return 50;
            }
        };
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([$agent], $resolver);
        
        $inputContext = [
            'customer_id' => 'cust_123',
            'query' => 'What are my options?',
            'session_data' => ['key' => 'value'],
        ];
        
        $coordinator->process($inputContext);
        
        // Agent should receive the full context
        $this->assertEquals($inputContext, $contextReceived);
    }

    public function testCoordinatorAddAgent(): void
    {
        $agent1 = $this->createTestAgent('agent_1', 'type', 100, []);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([$agent1], $resolver);
        
        $results1 = $coordinator->process([]);
        $this->assertCount(1, $results1);
        
        // Add another agent
        $agent2 = $this->createTestAgent('agent_2', 'type', 90, []);
        $coordinator->addAgent($agent2);
        
        $results2 = $coordinator->process([]);
        $this->assertCount(2, $results2);
    }

    public function testCoordinatorRemoveAgent(): void
    {
        $agent1 = $this->createTestAgent('agent_1', 'type', 100, []);
        $agent2 = $this->createTestAgent('agent_2', 'type', 90, []);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([$agent1, $agent2], $resolver);
        
        $results1 = $coordinator->process([]);
        $this->assertCount(2, $results1);
        
        // Remove an agent
        $coordinator->removeAgent('agent_1');
        
        $results2 = $coordinator->process([]);
        $this->assertCount(1, $results2);
        $this->assertArrayNotHasKey('agent_1', $results2);
    }

    public function testCoordinatorGetAgentById(): void
    {
        $agent1 = $this->createTestAgent('agent_1', 'type_a', 100, []);
        $agent2 = $this->createTestAgent('agent_2', 'type_b', 90, []);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([$agent1, $agent2], $resolver);
        
        $found = $coordinator->getAgent('agent_1');
        
        $this->assertNotNull($found);
        $this->assertEquals('agent_1', $found->getAgentId());
        $this->assertEquals('type_a', $found->getAgentType());
    }

    public function testCoordinatorGetNonexistentAgent(): void
    {
        $agent1 = $this->createTestAgent('agent_1', 'type', 100, []);
        
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        $coordinator = new MultiAgentCoordinator([$agent1], $resolver);
        
        $notFound = $coordinator->getAgent('nonexistent');
        
        $this->assertNull($notFound);
    }
}
