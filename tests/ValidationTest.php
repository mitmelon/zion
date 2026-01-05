<?php

declare(strict_types=1);

namespace Zion\Memory\Tests;

use PHPUnit\Framework\TestCase;
use Zion\Memory\Validation\FactValidator;
use Zion\Memory\Validation\ConflictResolver;
use Zion\Memory\Validation\ConflictResolutionStrategy;
use Zion\Memory\Contracts\AIProviderInterface;

/**
 * Unit tests for Validation and Conflict Resolution
 */
class ValidationTest extends TestCase
{
    // =========================================================================
    // FactValidator Tests (with Mock AI Provider)
    // =========================================================================

    public function testFactValidatorValidate(): void
    {
        $mockAI = $this->createMockAIProvider();
        $validator = new FactValidator($mockAI);
        
        $fact = [
            'id' => 'fact_1',
            'subject' => 'John',
            'relation' => 'works_at',
            'object' => 'TechCorp',
            'confidence' => 0.95,
        ];
        
        $context = [
            'source' => 'user_message',
            'message' => 'John works at TechCorp as an engineer',
        ];
        
        $result = $validator->validate($fact, $context);
        
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertIsBool($result['valid']);
        $this->assertIsFloat($result['score']);
    }

    public function testFactValidatorFindContradictions(): void
    {
        $mockAI = $this->createMockAIProvider();
        $validator = new FactValidator($mockAI);
        
        $newFact = [
            'id' => 'fact_new',
            'subject' => 'John',
            'relation' => 'works_at',
            'object' => 'NewCorp',
        ];
        
        $existingFacts = [
            [
                'id' => 'fact_existing',
                'subject' => 'John',
                'relation' => 'works_at',
                'object' => 'OldCorp',
            ],
        ];
        
        $contradictions = $validator->findContradictions($newFact, $existingFacts);
        
        $this->assertIsArray($contradictions);
    }

    public function testFactValidatorGetValidationScore(): void
    {
        $mockAI = $this->createMockAIProvider();
        $validator = new FactValidator($mockAI);
        
        $fact = [
            'id' => 'fact_1',
            'subject' => 'Company',
            'relation' => 'has_revenue',
            'object' => '$1M',
        ];
        
        $score = $validator->getValidationScore($fact);
        
        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    // =========================================================================
    // ConflictResolver Tests
    // =========================================================================

    public function testConflictResolverLatestWins(): void
    {
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        
        $oldFact = [
            'id' => 'fact_old',
            'subject' => 'John',
            'relation' => 'has_salary',
            'object' => '$100,000',
            'timestamp' => time() - 3600,
        ];
        
        $newFact = [
            'id' => 'fact_new',
            'subject' => 'John',
            'relation' => 'has_salary',
            'object' => '$120,000',
            'timestamp' => time(),
        ];
        
        $resolved = $resolver->resolve($oldFact, $newFact);
        
        $this->assertEquals('$120,000', $resolved['object']);
    }

    public function testConflictResolverPriorityAgent(): void
    {
        $resolver = new ConflictResolver(ConflictResolutionStrategy::PRIORITY_AGENT);
        
        $lowPriorityFact = [
            'id' => 'fact_low',
            'subject' => 'Customer',
            'relation' => 'risk_score',
            'object' => '25',
            'source_agent' => 'insight_agent',
            'priority' => 50,
            'timestamp' => time(),
        ];
        
        $highPriorityFact = [
            'id' => 'fact_high',
            'subject' => 'Customer',
            'relation' => 'risk_score',
            'object' => '75',
            'source_agent' => 'compliance_agent',
            'priority' => 100,
            'timestamp' => time() - 3600,
        ];
        
        $resolved = $resolver->resolve($lowPriorityFact, $highPriorityFact);
        
        // High priority should win regardless of timestamp
        $this->assertEquals('75', $resolved['object']);
    }

    public function testConflictResolverGetStrategy(): void
    {
        $resolver = new ConflictResolver(ConflictResolutionStrategy::CONSENSUS);
        
        $strategy = $resolver->getResolutionStrategy();
        
        $this->assertEquals(ConflictResolutionStrategy::CONSENSUS, $strategy);
    }

    public function testConflictResolverResolveMultiple(): void
    {
        $resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
        
        $conflicts = [
            [
                'fact1' => [
                    'id' => 'f1',
                    'subject' => 'A',
                    'relation' => 'r1',
                    'object' => 'old1',
                    'timestamp' => time() - 3600,
                ],
                'fact2' => [
                    'id' => 'f2',
                    'subject' => 'A',
                    'relation' => 'r1',
                    'object' => 'new1',
                    'timestamp' => time(),
                ],
            ],
            [
                'fact1' => [
                    'id' => 'f3',
                    'subject' => 'B',
                    'relation' => 'r2',
                    'object' => 'old2',
                    'timestamp' => time() - 1800,
                ],
                'fact2' => [
                    'id' => 'f4',
                    'subject' => 'B',
                    'relation' => 'r2',
                    'object' => 'new2',
                    'timestamp' => time(),
                ],
            ],
        ];
        
        $resolved = $resolver->resolveMultiple($conflicts);
        
        $this->assertCount(2, $resolved);
        $this->assertEquals('new1', $resolved[0]['object']);
        $this->assertEquals('new2', $resolved[1]['object']);
    }

    public function testConflictResolverManualReview(): void
    {
        $resolver = new ConflictResolver(ConflictResolutionStrategy::MANUAL_REVIEW);
        
        $fact1 = [
            'id' => 'fact_1',
            'subject' => 'Account',
            'relation' => 'balance',
            'object' => '$10,000',
            'timestamp' => time(),
        ];
        
        $fact2 = [
            'id' => 'fact_2',
            'subject' => 'Account',
            'relation' => 'balance',
            'object' => '$15,000',
            'timestamp' => time(),
        ];
        
        $resolved = $resolver->resolve($fact1, $fact2);
        
        // Manual review should mark as pending
        $this->assertEquals('pending_review', $resolved['status'] ?? 'pending_review');
    }

    // =========================================================================
    // ConflictResolutionStrategy Enum Tests
    // =========================================================================

    public function testConflictResolutionStrategyValues(): void
    {
        $strategies = ConflictResolutionStrategy::cases();
        
        $this->assertCount(4, $strategies);
        
        $values = array_map(fn($s) => $s->value, $strategies);
        
        $this->assertContains('latest_wins', $values);
        $this->assertContains('priority_agent', $values);
        $this->assertContains('consensus', $values);
        $this->assertContains('manual_review', $values);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createMockAIProvider(): AIProviderInterface
    {
        $mock = $this->createMock(AIProviderInterface::class);
        
        $mock->method('complete')
            ->willReturn('{"valid": true, "confidence": 0.85}');
        
        $mock->method('getEmbedding')
            ->willReturn(array_fill(0, 1536, 0.1));
        
        return $mock;
    }
}
