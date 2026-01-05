<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface SummarizerInterface
 * 
 * Defines the contract for AI-powered summarization of narrative memory.
 * Used by Mindscape RAG to generate contextual summaries of conversations.
 * 
 * @package Zion\Memory\Contracts
 */
interface SummarizerInterface
{
    /**
     * Generate a summary of conversation messages.
     *
     * @param array $messages Array of messages to summarize
     * @param string|null $previousSummary Optional previous summary for incremental updates
     * @param array $options Summarization options (max_length, focus_areas, etc.)
     * @return string Generated summary text
     */
    public function summarize(array $messages, ?string $previousSummary = null, array $options = []): string;

    /**
     * Generate an incremental summary update.
     *
     * @param string $existingSummary Current summary
     * @param array $newMessages New messages to incorporate
     * @param array $options Summarization options
     * @return string Updated summary text
     */
    public function updateSummary(string $existingSummary, array $newMessages, array $options = []): string;

    /**
     * Generate a multi-session summary (aggregate across sessions).
     *
     * @param array $sessionSummaries Array of session summaries
     * @param array $options Summarization options
     * @return string Aggregated summary text
     */
    public function aggregateSummaries(array $sessionSummaries, array $options = []): string;

    /**
     * Extract key topics and themes from messages.
     *
     * @param array $messages Array of messages to analyze
     * @return array Array of topics with relevance scores
     */
    public function extractTopics(array $messages): array;

    /**
     * Get the summarization model/service being used.
     *
     * @return string Model identifier
     */
    public function getModel(): string;

    /**
     * Set configuration options for the summarizer.
     *
     * @param array $config Configuration options
     * @return void
     */
    public function configure(array $config): void;
}
