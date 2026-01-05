<?php

declare(strict_types=1);

namespace Zion\Memory\Memory;

use Zion\Memory\Contracts\MemoryStorageAdapter;
use Zion\Memory\Contracts\SummarizerInterface;
use Zion\Memory\Contracts\AuditLoggerInterface;

/**
 * Class MindscapeMemory
 * 
 * Mindscape RAG implementation for narrative context memory.
 * Stores user-AI interactions and generates AI-powered summaries.
 * Maintains recent conversational context for AI prompts.
 * 
 * @package Zion\Memory\Memory
 */
class MindscapeMemory
{
    /**
     * @var MemoryStorageAdapter Storage adapter
     */
    private MemoryStorageAdapter $storage;

    /**
     * @var SummarizerInterface Summarizer
     */
    private SummarizerInterface $summarizer;

    /**
     * @var AuditLoggerInterface|null Audit logger
     */
    private ?AuditLoggerInterface $auditLogger;

    /**
     * @var array Configuration
     */
    private array $config = [
        'max_recent_messages' => 20,
        'summarize_threshold' => 10,
        'auto_summarize' => true,
        'prune_after_days' => 30,
        'include_summary_in_context' => true,
    ];

    /**
     * Constructor.
     *
     * @param MemoryStorageAdapter $storage Storage adapter
     * @param SummarizerInterface $summarizer Summarizer
     * @param AuditLoggerInterface|null $auditLogger Optional audit logger
     * @param array $config Configuration options
     */
    public function __construct(
        MemoryStorageAdapter $storage,
        SummarizerInterface $summarizer,
        ?AuditLoggerInterface $auditLogger = null,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->summarizer = $summarizer;
        $this->auditLogger = $auditLogger;
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Store a user message.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param string $content Message content
     * @param array $metadata Additional metadata
     * @return string Message ID
     */
    public function storeUserMessage(
        string $tenantId,
        string $sessionId,
        string $content,
        array $metadata = []
    ): string {
        return $this->storeMessage($tenantId, $sessionId, 'user', $content, $metadata);
    }

    /**
     * Store an AI/assistant message.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param string $content Message content
     * @param array $metadata Additional metadata
     * @return string Message ID
     */
    public function storeAssistantMessage(
        string $tenantId,
        string $sessionId,
        string $content,
        array $metadata = []
    ): string {
        return $this->storeMessage($tenantId, $sessionId, 'assistant', $content, $metadata);
    }

    /**
     * Store a message.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param string $role Message role (user, assistant, system)
     * @param string $content Message content
     * @param array $metadata Additional metadata
     * @return string Message ID
     */
    public function storeMessage(
        string $tenantId,
        string $sessionId,
        string $role,
        string $content,
        array $metadata = []
    ): string {
        $message = [
            'role' => $role,
            'content' => $content,
            'timestamp' => time(),
            'metadata' => $metadata,
        ];

        $messageId = $this->storage->storeMessage($tenantId, $sessionId, $message);

        // Log the action
        $this->log($tenantId, AuditLoggerInterface::ACTION_MESSAGE_STORED, [
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'role' => $role,
            'content_length' => strlen($content),
        ]);

        // Auto-summarize if threshold reached
        if ($this->config['auto_summarize']) {
            $this->checkAndSummarize($tenantId, $sessionId);
        }

        return $messageId;
    }

    /**
     * Get recent messages for a session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param int|null $limit Maximum messages (null = use config)
     * @return array Messages
     */
    public function getRecentMessages(
        string $tenantId,
        string $sessionId,
        ?int $limit = null
    ): array {
        $limit = $limit ?? $this->config['max_recent_messages'];
        
        $messages = $this->storage->getMessages($tenantId, $sessionId, $limit);
        
        $this->log($tenantId, AuditLoggerInterface::ACTION_MESSAGE_RETRIEVED, [
            'session_id' => $sessionId,
            'count' => count($messages),
        ]);

        return $messages;
    }

    /**
     * Build context for AI prompt.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param array $options Context building options
     * @return array Context with summary and recent messages
     */
    public function buildContext(
        string $tenantId,
        string $sessionId,
        array $options = []
    ): array {
        $context = [
            'summary' => null,
            'messages' => [],
            'metadata' => [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'built_at' => time(),
            ],
        ];

        // Get summary if configured
        if ($this->config['include_summary_in_context']) {
            $summaryData = $this->storage->getLatestSummary($tenantId, $sessionId);
            $context['summary'] = $summaryData['summary'] ?? null;
            $context['metadata']['summary_timestamp'] = $summaryData['timestamp'] ?? null;
        }

        // Get recent messages
        $limit = $options['max_messages'] ?? $this->config['max_recent_messages'];
        $context['messages'] = $this->storage->getMessages($tenantId, $sessionId, $limit);
        
        // Reverse to get chronological order (oldest first)
        $context['messages'] = array_reverse($context['messages']);

        $context['metadata']['message_count'] = count($context['messages']);
        $context['metadata']['total_message_count'] = $this->storage->getMessageCount($tenantId, $sessionId);

        return $context;
    }

    /**
     * Build formatted prompt context string.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param array $options Options
     * @return string Formatted context
     */
    public function buildPromptContext(
        string $tenantId,
        string $sessionId,
        array $options = []
    ): string {
        $context = $this->buildContext($tenantId, $sessionId, $options);
        
        $formatted = '';
        
        // Add summary if available
        if (!empty($context['summary'])) {
            $formatted .= "CONVERSATION SUMMARY:\n{$context['summary']}\n\n";
        }
        
        // Add recent messages
        if (!empty($context['messages'])) {
            $formatted .= "RECENT CONVERSATION:\n";
            foreach ($context['messages'] as $message) {
                $role = ucfirst($message['role'] ?? 'unknown');
                $content = $message['content'] ?? '';
                $formatted .= "{$role}: {$content}\n\n";
            }
        }
        
        return trim($formatted);
    }

    /**
     * Generate a summary for a session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param bool $force Force regeneration even if recent summary exists
     * @return string Generated summary
     */
    public function generateSummary(
        string $tenantId,
        string $sessionId,
        bool $force = false
    ): string {
        // Get all messages
        $messages = $this->storage->getMessages($tenantId, $sessionId, 1000);
        
        if (empty($messages)) {
            return '';
        }
        
        // Reverse to chronological order
        $messages = array_reverse($messages);

        // Get previous summary
        $previousSummary = null;
        if (!$force) {
            $summaryData = $this->storage->getLatestSummary($tenantId, $sessionId);
            $previousSummary = $summaryData['summary'] ?? null;
        }

        // Generate new summary
        $summary = $this->summarizer->summarize($messages, $previousSummary);

        // Store the summary
        $this->storage->storeSummary($tenantId, $sessionId, $summary, [
            'message_count' => count($messages),
            'generated_at' => time(),
        ]);

        $this->log($tenantId, AuditLoggerInterface::ACTION_SUMMARY_GENERATED, [
            'session_id' => $sessionId,
            'message_count' => count($messages),
            'summary_length' => strlen($summary),
        ]);

        return $summary;
    }

    /**
     * Update summary incrementally with new messages.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param array $newMessages New messages to incorporate
     * @return string Updated summary
     */
    public function updateSummary(
        string $tenantId,
        string $sessionId,
        array $newMessages
    ): string {
        $summaryData = $this->storage->getLatestSummary($tenantId, $sessionId);
        $existingSummary = $summaryData['summary'] ?? '';

        if (empty($existingSummary)) {
            return $this->generateSummary($tenantId, $sessionId);
        }

        $updatedSummary = $this->summarizer->updateSummary($existingSummary, $newMessages);

        $this->storage->storeSummary($tenantId, $sessionId, $updatedSummary, [
            'incremental_update' => true,
            'new_message_count' => count($newMessages),
            'updated_at' => time(),
        ]);

        return $updatedSummary;
    }

    /**
     * Get the latest summary for a session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return string|null Summary or null if none exists
     */
    public function getSummary(string $tenantId, string $sessionId): ?string
    {
        $summaryData = $this->storage->getLatestSummary($tenantId, $sessionId);
        return $summaryData['summary'] ?? null;
    }

    /**
     * Extract topics from session messages.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return array Topics with relevance scores
     */
    public function extractTopics(string $tenantId, string $sessionId): array
    {
        $messages = $this->storage->getMessages($tenantId, $sessionId, 100);
        
        if (empty($messages)) {
            return [];
        }

        return $this->summarizer->extractTopics(array_reverse($messages));
    }

    /**
     * Prune old messages.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param int|null $olderThanDays Days threshold (null = use config)
     * @return int Number of messages pruned
     */
    public function pruneOldMessages(
        string $tenantId,
        string $sessionId,
        ?int $olderThanDays = null
    ): int {
        $days = $olderThanDays ?? $this->config['prune_after_days'];
        $seconds = $days * 24 * 60 * 60;

        $pruned = $this->storage->pruneMessages($tenantId, $sessionId, $seconds);

        if ($pruned > 0) {
            $this->log($tenantId, AuditLoggerInterface::ACTION_MEMORY_PRUNED, [
                'session_id' => $sessionId,
                'messages_pruned' => $pruned,
                'threshold_days' => $days,
            ]);
        }

        return $pruned;
    }

    /**
     * Clear all messages for a session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return bool Success
     */
    public function clearSession(string $tenantId, string $sessionId): bool
    {
        $result = $this->storage->clearSession($tenantId, $sessionId);

        $this->log($tenantId, AuditLoggerInterface::ACTION_SESSION_CLEARED, [
            'session_id' => $sessionId,
        ]);

        return $result;
    }

    /**
     * Get session statistics.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return array Statistics
     */
    public function getSessionStats(string $tenantId, string $sessionId): array
    {
        $messageCount = $this->storage->getMessageCount($tenantId, $sessionId);
        $summaries = $this->storage->getSummaries($tenantId, $sessionId);
        $latestSummary = $this->storage->getLatestSummary($tenantId, $sessionId);

        return [
            'message_count' => $messageCount,
            'summary_count' => count($summaries),
            'latest_summary_at' => $latestSummary['timestamp'] ?? null,
            'has_summary' => !empty($latestSummary),
        ];
    }

    /**
     * Check if summarization is needed and perform it.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return void
     */
    private function checkAndSummarize(string $tenantId, string $sessionId): void
    {
        $messageCount = $this->storage->getMessageCount($tenantId, $sessionId);
        $latestSummary = $this->storage->getLatestSummary($tenantId, $sessionId);
        
        $messagesSinceSummary = $messageCount;
        
        if ($latestSummary) {
            // Calculate messages since last summary
            $summaryMessageCount = $latestSummary['metadata']['message_count'] ?? 0;
            $messagesSinceSummary = $messageCount - $summaryMessageCount;
        }
        
        if ($messagesSinceSummary >= $this->config['summarize_threshold']) {
            $this->generateSummary($tenantId, $sessionId);
        }
    }

    /**
     * Log an action.
     *
     * @param string $tenantId Tenant ID
     * @param string $action Action type
     * @param array $data Action data
     * @return void
     */
    private function log(string $tenantId, string $action, array $data): void
    {
        if ($this->auditLogger) {
            $this->auditLogger->log($tenantId, $action, $data, [
                'component' => 'mindscape_memory',
            ]);
        }
    }

    /**
     * Get configuration.
     *
     * @return array Configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration.
     *
     * @param array $config New configuration
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }
}
