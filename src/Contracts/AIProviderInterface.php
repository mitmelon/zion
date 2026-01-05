<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface AIProviderInterface
 * 
 * Defines the contract for AI provider integration (Gemini, Anthropic, etc.)
 * Used by Summarizer and FactExtractor for AI-powered operations.
 * 
 * @package Zion\Memory\Contracts
 */
interface AIProviderInterface
{
    /**
     * Send a completion request to the AI provider.
     *
     * @param string $prompt The prompt to send
     * @param array $options Options (model, max_tokens, temperature, etc.)
     * @return string AI response content
     */
    public function complete(string $prompt, array $options = []): string;

    /**
     * Send a chat completion request to the AI provider.
     *
     * @param array $messages Array of messages with 'role' and 'content'
     * @param array $options Options (model, max_tokens, temperature, etc.)
     * @return string AI response content
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Send a structured output request (JSON mode).
     *
     * @param string $prompt The prompt to send
     * @param array $schema Expected JSON schema
     * @param array $options Additional options
     * @return array Parsed JSON response
     */
    public function structuredOutput(string $prompt, array $schema, array $options = []): array;

    /**
     * Generate embeddings for text.
     *
     * @param string|array $text Text or array of texts
     * @param array $options Embedding options
     * @return array Embedding vector(s)
     */
    public function embed(string|array $text, array $options = []): array;

    /**
     * Get the provider name.
     *
     * @return string Provider name
     */
    public function getProviderName(): string;

    /**
     * Get available models.
     *
     * @return array Array of model identifiers
     */
    public function getAvailableModels(): array;

    /**
     * Set the API key.
     *
     * @param string $apiKey API key
     * @return void
     */
    public function setApiKey(string $apiKey): void;

    /**
     * Set the default model.
     *
     * @param string $model Model identifier
     * @return void
     */
    public function setDefaultModel(string $model): void;

    /**
     * Get rate limit status.
     *
     * @return array Rate limit information
     */
    public function getRateLimitStatus(): array;
}
