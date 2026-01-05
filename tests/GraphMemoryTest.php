<?php

declare(strict_types=1);

namespace Zion\Memory\Tests;

use PHPUnit\Framework\TestCase;
use Zion\Memory\Graph\GraphMemory;
use Zion\Memory\Contracts\GraphMemoryAdapter;
use Zion\Memory\Contracts\FactExtractorInterface;
use Zion\Memory\Contracts\FactValidatorInterface;

/**
 * Unit tests for Graph Memory (Graph RAG)
 */
class GraphMemoryTest extends TestCase
{
    private GraphMemoryAdapter $mockGraphAdapter;
    private FactExtractorInterface $mockExtractor;
    private FactValidatorInterface $mockValidator;

    protected function setUp(): void
    {
        $this->mockGraphAdapter = $this->createMock(GraphMemoryAdapter::class);
        $this->mockExtractor = $this->createMock(FactExtractorInterface::class);
        $this->mockValidator = $this->createMock(FactValidatorInterface::class);
    }

    public function testAddFactsFromText(): void
    {
        $extractedFacts = [
            [
                'subject' => 'John',
                'relation' => 'works_at',
                'object' => 'TechCorp',
            ],
            [
                'subject' => 'John',
                'relation' => 'has_role',
                'object' => 'Engineer',
            ],
        ];
        
        $this->mockExtractor->method('extractFacts')
            ->willReturn($extractedFacts);
        
        $this->mockValidator->method('validate')
            ->willReturn(['valid' => true, 'score' => 0.9]);
        
        $this->mockValidator->method('findContradictions')
            ->willReturn([]);
        
        $this->mockGraphAdapter->method('getFacts')
            ->willReturn([]);
        
        $this->mockGraphAdapter->expects($this->exactly(2))
            ->method('addFact');
        
        $graphMemory = new GraphMemory(
            $this->mockGraphAdapter,
            $this->mockExtractor,
            $this->mockValidator,
            'tenant1'
        );
        
        $result = $graphMemory->addFactsFromText('John works at TechCorp as an Engineer');
        
        $this->assertArrayHasKey('facts_added', $result);
        $this->assertEquals(2, $result['facts_added']);
    }

    public function testAddFactsWithContradictions(): void
    {
        $extractedFacts = [
            [
                'subject' => 'John',
                'relation' => 'works_at',
                'object' => 'NewCorp',
            ],
        ];
        
        $existingFacts = [
            [
                'id' => 'existing_fact',
                'subject' => 'John',
                'relation' => 'works_at',
                'object' => 'OldCorp',
            ],
        ];
        
        $this->mockExtractor->method('extractFacts')
            ->willReturn($extractedFacts);
        
        $this->mockValidator->method('validate')
            ->willReturn(['valid' => true, 'score' => 0.9]);
        
        $this->mockValidator->method('findContradictions')
            ->willReturn([
                [
                    'existing_fact' => $existingFacts[0],
                    'new_fact' => $extractedFacts[0],
                    'description' => 'Conflicting workplace information',
                ],
            ]);
        
        $this->mockGraphAdapter->method('getFacts')
            ->willReturn($existingFacts);
        
        $graphMemory = new GraphMemory(
            $this->mockGraphAdapter,
            $this->mockExtractor,
            $this->mockValidator,
            'tenant1'
        );
        
        $result = $graphMemory->addFactsFromText('John now works at NewCorp');
        
        $this->assertArrayHasKey('contradictions', $result);
        $this->assertNotEmpty($result['contradictions']);
    }

    public function testInvalidFactsNotAdded(): void
    {
        $extractedFacts = [
            [
                'subject' => 'Uncertain',
                'relation' => 'might_be',
                'object' => 'Something',
            ],
        ];
        
        $this->mockExtractor->method('extractFacts')
            ->willReturn($extractedFacts);
        
        // Mark fact as invalid
        $this->mockValidator->method('validate')
            ->willReturn(['valid' => false, 'score' => 0.2]);
        
        $this->mockValidator->method('findContradictions')
            ->willReturn([]);
        
        $this->mockGraphAdapter->method('getFacts')
            ->willReturn([]);
        
        // Should NOT add fact
        $this->mockGraphAdapter->expects($this->never())
            ->method('addFact');
        
        $graphMemory = new GraphMemory(
            $this->mockGraphAdapter,
            $this->mockExtractor,
            $this->mockValidator,
            'tenant1'
        );
        
        $result = $graphMemory->addFactsFromText('Uncertain statement');
        
        $this->assertEquals(0, $result['facts_added']);
    }

    public function testQueryRelated(): void
    {
        $this->mockGraphAdapter->method('findRelated')
            ->with('tenant1', 'John', 'works_at')
            ->willReturn(['TechCorp', 'StartupInc']);
        
        $graphMemory = new GraphMemory(
            $this->mockGraphAdapter,
            $this->mockExtractor,
            $this->mockValidator,
            'tenant1'
        );
        
        $related = $graphMemory->queryRelated('John', 'works_at');
        
        $this->assertCount(2, $related);
        $this->assertContains('TechCorp', $related);
        $this->assertContains('StartupInc', $related);
    }

    public function testGetFactsForEntity(): void
    {
        $entityFacts = [
            [
                'id' => 'fact_1',
                'subject' => 'John',
                'relation' => 'has_name',
                'object' => 'John Smith',
            ],
            [
                'id' => 'fact_2',
                'subject' => 'John',
                'relation' => 'works_at',
                'object' => 'TechCorp',
            ],
        ];
        
        $this->mockGraphAdapter->method('getFactsByEntity')
            ->with('tenant1', 'John')
            ->willReturn($entityFacts);
        
        $graphMemory = new GraphMemory(
            $this->mockGraphAdapter,
            $this->mockExtractor,
            $this->mockValidator,
            'tenant1'
        );
        
        $facts = $graphMemory->getFactsForEntity('John');
        
        $this->assertCount(2, $facts);
    }

    public function testEmptyTextReturnsNoFacts(): void
    {
        $this->mockExtractor->method('extractFacts')
            ->willReturn([]);
        
        $graphMemory = new GraphMemory(
            $this->mockGraphAdapter,
            $this->mockExtractor,
            $this->mockValidator,
            'tenant1'
        );
        
        $result = $graphMemory->addFactsFromText('');
        
        $this->assertEquals(0, $result['facts_added']);
        $this->assertEmpty($result['contradictions']);
    }

    public function testTenantIsolation(): void
    {
        $tenant1Adapter = $this->createMock(GraphMemoryAdapter::class);
        $tenant2Adapter = $this->createMock(GraphMemoryAdapter::class);
        
        $tenant1Adapter->method('getFactsByEntity')
            ->with('tenant1', 'SharedEntity')
            ->willReturn([['subject' => 'SharedEntity', 'relation' => 'belongs_to', 'object' => 'Tenant1']]);
        
        $tenant2Adapter->method('getFactsByEntity')
            ->with('tenant2', 'SharedEntity')
            ->willReturn([['subject' => 'SharedEntity', 'relation' => 'belongs_to', 'object' => 'Tenant2']]);
        
        $graph1 = new GraphMemory($tenant1Adapter, $this->mockExtractor, $this->mockValidator, 'tenant1');
        $graph2 = new GraphMemory($tenant2Adapter, $this->mockExtractor, $this->mockValidator, 'tenant2');
        
        $facts1 = $graph1->getFactsForEntity('SharedEntity');
        $facts2 = $graph2->getFactsForEntity('SharedEntity');
        
        $this->assertEquals('Tenant1', $facts1[0]['object']);
        $this->assertEquals('Tenant2', $facts2[0]['object']);
    }

    public function testContextualFactExtraction(): void
    {
        $context = [
            'previous_facts' => [
                ['subject' => 'John', 'relation' => 'is_customer', 'object' => 'true'],
            ],
        ];
        
        $this->mockExtractor->expects($this->once())
            ->method('extractFactsWithContext')
            ->with('John wants premium services', $context)
            ->willReturn([
                ['subject' => 'John', 'relation' => 'wants', 'object' => 'premium services'],
            ]);
        
        $this->mockValidator->method('validate')
            ->willReturn(['valid' => true, 'score' => 0.85]);
        
        $this->mockValidator->method('findContradictions')
            ->willReturn([]);
        
        $this->mockGraphAdapter->method('getFacts')
            ->willReturn($context['previous_facts']);
        
        $graphMemory = new GraphMemory(
            $this->mockGraphAdapter,
            $this->mockExtractor,
            $this->mockValidator,
            'tenant1'
        );
        
        $result = $graphMemory->addFactsFromTextWithContext('John wants premium services', $context);
        
        $this->assertEquals(1, $result['facts_added']);
    }
}
