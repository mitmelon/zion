<?php

declare(strict_types=1);

namespace Zion\Memory\AI;

use Zion\Memory\Contracts\SummarizerInterface;
use Zion\Memory\Contracts\AIProviderInterface;

/**
 * Class AISummarizer
 * 
 * AI-powered summarizer for Mindscape RAG narrative memory.
 * Generates contextual summaries of user-AI interactions.
 * 
 * @package Zion\Memory\AI
 */
class AISummarizer implements SummarizerInterface
{
    /**
     * @var AIProviderInterface AI provider
     */
    private AIProviderInterface $aiProvider;

    /**
     * @var array Configuration options
     */
    private array $config = [
        'max_summary_length' => 500,
        'min_messages_for_summary' => 3,
        'include_key_facts' => true,
        'focus_on_banking' => true,
        'language' => 'en',
    ];

    /**
     * Constructor.
     *
     * @param AIProviderInterface $aiProvider AI provider instance
     * @param array $config Optional configuration
     */
    public function __construct(AIProviderInterface $aiProvider, array $config = [])
    {
        $this->aiProvider = $aiProvider;
        $this->config = array_merge($this->config, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function summarize(array $messages, ?string $previousSummary = null, array $options = []): string
    {
        if (empty($messages)) {
            return $previousSummary ?? '';
        }

        $config = array_merge($this->config, $options);
        
        $formattedMessages = $this->formatMessages($messages);
        
        $prompt = $this->buildSummarizationPrompt(
            $formattedMessages,
            $previousSummary,
            $config
        );

        $summary = $this->aiProvider->complete($prompt, [
            'temperature' => 0.3,
            'max_tokens' => $config['max_summary_length'] * 2,
        ]);

        return $this->cleanSummary($summary);
    }

    /**
     * {@inheritdoc}
     */
    public function updateSummary(string $existingSummary, array $newMessages, array $options = []): string
    {
        if (empty($newMessages)) {
            return $existingSummary;
        }

        $config = array_merge($this->config, $options);
        
        $formattedMessages = $this->formatMessages($newMessages);
        
        $prompt = <<<PROMPT
You are an expert at updating conversation summaries for a banking AI system.

EXISTING SUMMARY:
{$existingSummary}

NEW MESSAGES TO INCORPORATE:
{$formattedMessages}

INSTRUCTIONS:
1. Update the existing summary to include relevant information from the new messages
2. Keep the summary concise (max {$config['max_summary_length']} words)
3. Preserve important banking-related details (accounts, transactions, customer concerns)
4. Remove outdated or superseded information
5. Maintain chronological context
6. Focus on actionable and relevant information

OUTPUT: Provide only the updated summary, no explanations.
PROMPT;

        $summary = $this->aiProvider->complete($prompt, [
            'temperature' => 0.3,
            'max_tokens' => $config['max_summary_length'] * 2,
        ]);

        return $this->cleanSummary($summary);
    }

    /**
     * {@inheritdoc}
     */
    public function aggregateSummaries(array $sessionSummaries, array $options = []): string
    {
        if (empty($sessionSummaries)) {
            return '';
        }

        if (count($sessionSummaries) === 1) {
            return reset($sessionSummaries);
        }

        $config = array_merge($this->config, $options);
        
        $summariesText = '';
        foreach ($sessionSummaries as $index => $summary) {
            $summariesText .= "Session " . ($index + 1) . ":\n{$summary}\n\n";
        }

        $prompt = <<<PROMPT
You are an expert at creating aggregate summaries for a banking AI system.

INDIVIDUAL SESSION SUMMARIES:
{$summariesText}

INSTRUCTIONS:
1. Create a unified summary that captures the key information from all sessions
2. Identify and highlight recurring themes or issues
3. Note any progression or changes in customer needs
4. Keep the aggregate summary comprehensive but concise
5. Prioritize banking-relevant information
6. Maximum length: {$config['max_summary_length']} words

OUTPUT: Provide only the aggregate summary, no explanations.
PROMPT;

        $summary = $this->aiProvider->complete($prompt, [
            'temperature' => 0.3,
            'max_tokens' => $config['max_summary_length'] * 2,
        ]);

        return $this->cleanSummary($summary);
    }

    /**
     * {@inheritdoc}
     */
    public function extractTopics(array $messages): array
    {
        if (empty($messages)) {
            return [];
        }

        $formattedMessages = $this->formatMessages($messages);
        
        $prompt = <<<PROMPT
Analyze the following banking conversation and extract the main topics discussed.

CONVERSATION:
{$formattedMessages}

INSTRUCTIONS:
1. Identify 3-7 main topics from the conversation
2. Assign a relevance score (0.0-1.0) to each topic
3. Focus on banking-related topics (accounts, transactions, loans, investments, etc.)
4. Include customer intent or concern as a topic if relevant

OUTPUT FORMAT (JSON):
{
    "topics": [
        {"name": "topic_name", "relevance": 0.9, "keywords": ["keyword1", "keyword2"]},
        ...
    ]
}
PROMPT;

        $result = $this->aiProvider->structuredOutput($prompt, [
            'type' => 'object',
            'properties' => [
                'topics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'relevance' => ['type' => 'number'],
                            'keywords' => ['type' => 'array', 'items' => ['type' => 'string']]
                        ]
                    ]
                ]
            ]
        ]);

        return $result['topics'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getModel(): string
    {
        return $this->aiProvider->getProviderName();
    }

    /**
     * {@inheritdoc}
     */
    public function configure(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Format messages for prompt.
     *
     * @param array $messages Messages to format
     * @return string Formatted messages
     */
    private function formatMessages(array $messages): string
    {
        $formatted = '';
        
        foreach ($messages as $message) {
            $role = ucfirst($message['role'] ?? 'unknown');
            $content = $message['content'] ?? '';
            $timestamp = isset($message['timestamp']) 
                ? date('Y-m-d H:i:s', $message['timestamp'])
                : 'unknown';
            
            $formatted .= "[{$timestamp}] {$role}: {$content}\n\n";
        }
        
        return trim($formatted);
    }

    /**
     * Build the summarization prompt.
     *
     * @param string $formattedMessages Formatted messages
     * @param string|null $previousSummary Previous summary
     * @param array $config Configuration
     * @return string Prompt
     */
    private function buildSummarizationPrompt(
        string $formattedMessages,
        ?string $previousSummary,
        array $config
    ): string {
        $previousContext = '';
        if ($previousSummary) {
            $previousContext = <<<CTX

PREVIOUS CONTEXT SUMMARY:
{$previousSummary}

CTX;
        }

        $bankingFocus = $config['focus_on_banking'] 
            ? "\n- Emphasize banking-related information (accounts, transactions, balances, etc.)"
            : '';

        return <<<PROMPT
You are an expert conversation summarizer for a banking AI assistant system.
{$previousContext}
CURRENT CONVERSATION:
{$formattedMessages}

INSTRUCTIONS:
1. Create a concise summary of the conversation (max {$config['max_summary_length']} words)
2. Capture the main topics discussed and any decisions made
3. Note any pending questions or unresolved issues
4. Include relevant customer information mentioned
5. Preserve important context for future interactions{$bankingFocus}

OUTPUT: Provide only the summary, no explanations or preamble.
PROMPT;
    }

    /**
     * Clean and normalize the summary output.
     *
     * @param string $summary Raw summary
     * @return string Cleaned summary
     */
    private function cleanSummary(string $summary): string
    {
        // Remove common AI prefixes
        $prefixes = [
            'Summary:',
            'Here is the summary:',
            'Here\'s the summary:',
            'Updated summary:',
            'Aggregate summary:',
        ];
        
        foreach ($prefixes as $prefix) {
            if (stripos($summary, $prefix) === 0) {
                $summary = trim(substr($summary, strlen($prefix)));
            }
        }
        
        return trim($summary);
    }
}
