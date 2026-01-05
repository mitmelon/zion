<?php

declare(strict_types=1);

namespace Zion\Memory\Tests;

use PHPUnit\Framework\TestCase;
use Zion\Memory\Memory\MindscapeMemory;
use Zion\Memory\Contracts\MemoryStorageAdapter;
use Zion\Memory\Contracts\SummarizerInterface;

/**
 * Unit tests for Mindscape Memory (Narrative RAG)
 */
class MindscapeMemoryTest extends TestCase
{
    private MemoryStorageAdapter $mockStorage;
    private SummarizerInterface $mockSummarizer;

    protected function setUp(): void
    {
        $this->mockStorage = $this->createMock(MemoryStorageAdapter::class);
        $this->mockSummarizer = $this->createMock(SummarizerInterface::class);
    }

    public function testAddMessage(): void
    {
        $this->mockStorage->expects($this->once())
            ->method('addMessage')
            ->with('tenant1', 'session1', 'user', 'Hello world', $this->anything());
        
        $this->mockSummarizer->method('shouldSummarize')->willReturn(false);
        
        $memory = new MindscapeMemory(
            $this->mockStorage,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $memory->addMessage('user', 'Hello world');
    }

    public function testAddMessageWithMetadata(): void
    {
        $metadata = ['importance' => 'high', 'topic' => 'banking'];
        
        $this->mockStorage->expects($this->once())
            ->method('addMessage')
            ->with('tenant1', 'session1', 'user', 'Important message', $metadata);
        
        $this->mockSummarizer->method('shouldSummarize')->willReturn(false);
        
        $memory = new MindscapeMemory(
            $this->mockStorage,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $memory->addMessage('user', 'Important message', $metadata);
    }

    public function testGetRecentMessages(): void
    {
        $storedMessages = [
            ['role' => 'user', 'content' => 'Message 1', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => 'Message 2', 'timestamp' => time()],
            ['role' => 'user', 'content' => 'Message 3', 'timestamp' => time()],
        ];
        
        $this->mockStorage->method('getMessages')
            ->willReturn($storedMessages);
        
        $memory = new MindscapeMemory(
            $this->mockStorage,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $messages = $memory->getRecentMessages(2);
        
        $this->assertCount(2, $messages);
    }

    public function testBuildContext(): void
    {
        $storedMessages = [
            ['role' => 'user', 'content' => 'I want to open an account', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => 'I can help with that', 'timestamp' => time()],
        ];
        
        $summary = [
            'content' => 'Customer interested in banking services.',
            'timestamp' => time(),
        ];
        
        $this->mockStorage->method('getMessages')
            ->willReturn($storedMessages);
        
        $this->mockStorage->method('getSummary')
            ->willReturn($summary);
        
        $memory = new MindscapeMemory(
            $this->mockStorage,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $context = $memory->buildContext('What about savings?');
        
        $this->assertIsArray($context);
        $this->assertNotEmpty($context);
        
        // Should contain system context with summary
        $systemContext = array_filter($context, fn($c) => $c['role'] === 'system');
        $this->assertNotEmpty($systemContext);
    }

    public function testTriggerSummarization(): void
    {
        $messages = [];
        for ($i = 0; $i < 15; $i++) {
            $messages[] = ['role' => 'user', 'content' => "Message $i", 'timestamp' => time()];
        }
        
        $this->mockStorage->method('getMessages')
            ->willReturn($messages);
        
        $this->mockSummarizer->method('shouldSummarize')
            ->with($messages, $this->anything())
            ->willReturn(true);
        
        $this->mockSummarizer->expects($this->once())
            ->method('summarize')
            ->with($messages)
            ->willReturn('Summary of conversation');
        
        $this->mockStorage->expects($this->once())
            ->method('storeSummary')
            ->with('tenant1', 'session1', $this->callback(function($summary) {
                return $summary['content'] === 'Summary of conversation';
            }));
        
        $memory = new MindscapeMemory(
            $this->mockStorage,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $memory->triggerSummarization();
    }

    public function testSummarizationNotTriggeredWhenNotNeeded(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Short conversation', 'timestamp' => time()],
        ];
        
        $this->mockStorage->method('getMessages')
            ->willReturn($messages);
        
        $this->mockSummarizer->method('shouldSummarize')
            ->willReturn(false);
        
        $this->mockSummarizer->expects($this->never())
            ->method('summarize');
        
        $memory = new MindscapeMemory(
            $this->mockStorage,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $memory->triggerSummarization();
    }

    public function testBuildContextWithoutSummary(): void
    {
        $storedMessages = [
            ['role' => 'user', 'content' => 'First message', 'timestamp' => time()],
        ];
        
        $this->mockStorage->method('getMessages')
            ->willReturn($storedMessages);
        
        $this->mockStorage->method('getSummary')
            ->willReturn(null);
        
        $memory = new MindscapeMemory(
            $this->mockStorage,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $context = $memory->buildContext('New question');
        
        $this->assertIsArray($context);
        // Should still have context from messages
        $this->assertNotEmpty($context);
    }

    public function testMultipleSessionIsolation(): void
    {
        $mockStorage1 = $this->createMock(MemoryStorageAdapter::class);
        $mockStorage2 = $this->createMock(MemoryStorageAdapter::class);
        
        // First storage returns session1 messages
        $mockStorage1->method('getMessages')
            ->willReturn([['role' => 'user', 'content' => 'Session 1', 'timestamp' => time()]]);
        
        // Second storage returns session2 messages
        $mockStorage2->method('getMessages')
            ->willReturn([['role' => 'user', 'content' => 'Session 2', 'timestamp' => time()]]);
        
        $memory1 = new MindscapeMemory(
            $mockStorage1,
            $this->mockSummarizer,
            'tenant1',
            'session1'
        );
        
        $memory2 = new MindscapeMemory(
            $mockStorage2,
            $this->mockSummarizer,
            'tenant1',
            'session2'
        );
        
        $messages1 = $memory1->getRecentMessages(10);
        $messages2 = $memory2->getRecentMessages(10);
        
        $this->assertEquals('Session 1', $messages1[0]['content']);
        $this->assertEquals('Session 2', $messages2[0]['content']);
    }
}
