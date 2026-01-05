<?php

declare(strict_types=1);

namespace Zion\Memory\AI;

use Zion\Memory\Contracts\AIProviderInterface;

/**
 * Class GeminiProvider
 * 
 * Google Gemini API implementation of AIProviderInterface.
 * Supports Gemini 3 Pro (default), Gemini 3 Flash, and other Gemini models
 * for summarization, fact extraction, and embeddings.
 * 
 * @package Zion\Memory\AI
 */
class GeminiProvider implements AIProviderInterface
{
    /**
     * @var string API key
     */
    private string $apiKey;

    /**
     * @var string Default model
     */
    private string $defaultModel = 'gemini-3-pro';

    /**
     * @var string Default embedding model
     */
    private string $embeddingModel = 'text-embedding-004';

    /**
     * @var string API base URL
     */
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * @var array Rate limit info
     */
    private array $rateLimitInfo = [];

    /**
     * @var int Request timeout in seconds
     */
    private int $timeout = 120;

    /**
     * Constructor.
     *
     * @param string $apiKey Google AI API key
     * @param string|null $defaultModel Default model to use
     */
    public function __construct(string $apiKey, ?string $defaultModel = null)
    {
        $this->apiKey = $apiKey;
        
        if ($defaultModel !== null) {
            $this->defaultModel = $defaultModel;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function complete(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? $this->defaultModel;
        
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => $this->buildGenerationConfig($options),
        ];

        if (isset($options['system'])) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $options['system']]
                ]
            ];
        }

        $response = $this->makeRequest($model, 'generateContent', $payload);
        
        return $this->extractTextFromResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->defaultModel;
        
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Handle system messages separately
            if ($role === 'system') {
                $systemInstruction = [
                    'parts' => [
                        ['text' => $content]
                    ]
                ];
                continue;
            }

            // Map roles: assistant -> model for Gemini
            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [
                    ['text' => $content]
                ]
            ];
        }

        // Handle system from options if not in messages
        if ($systemInstruction === null && isset($options['system'])) {
            $systemInstruction = [
                'parts' => [
                    ['text' => $options['system']]
                ]
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => $this->buildGenerationConfig($options),
        ];

        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        $response = $this->makeRequest($model, 'generateContent', $payload);
        
        return $this->extractTextFromResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public function structuredOutput(string $prompt, array $schema, array $options = []): array
    {
        $model = $options['model'] ?? $this->defaultModel;
        
        $systemPrompt = "You are a helpful assistant that outputs JSON. " .
                       "Always respond with valid JSON that matches this schema: " .
                       json_encode($schema) . 
                       "\n\nRespond ONLY with valid JSON, no additional text or markdown.";
        
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt]
                ]
            ],
            'generationConfig' => array_merge(
                $this->buildGenerationConfig($options),
                [
                    'responseMimeType' => 'application/json',
                    'temperature' => $options['temperature'] ?? 0.1,
                ]
            ),
        ];

        // Add response schema if supported
        if (!empty($schema)) {
            $payload['generationConfig']['responseSchema'] = $this->convertToGeminiSchema($schema);
        }

        $response = $this->makeRequest($model, 'generateContent', $payload);
        $content = $this->extractTextFromResponse($response);
        
        // Clean up any markdown code blocks if present
        $content = $this->cleanJsonResponse($content);
        
        return json_decode($content, true) ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function embed(string|array $text, array $options = []): array
    {
        $model = $options['model'] ?? $this->embeddingModel;
        
        if (is_array($text)) {
            // Batch embedding
            $embeddings = [];
            foreach ($text as $t) {
                $embeddings[] = $this->embedSingle($t, $model);
            }
            return $embeddings;
        }
        
        return $this->embedSingle($text, $model);
    }

    /**
     * Generate embedding for a single text.
     *
     * @param string $text Text to embed
     * @param string $model Model to use
     * @return array Embedding vector
     */
    private function embedSingle(string $text, string $model): array
    {
        $payload = [
            'model' => "models/{$model}",
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ],
        ];

        $response = $this->makeRequest($model, 'embedContent', $payload);
        
        return $response['embedding']['values'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName(): string
    {
        return 'gemini';
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableModels(): array
    {
        return [
            // Gemini 3 models
            'gemini-3-pro',
            'gemini-3-flash',
            // Gemini 2.5 models
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            // Gemini 2.0 models (legacy)
            'gemini-2.0-flash',
            'gemini-2.0-flash-lite',
            // Gemini 1.5 models (legacy)
            'gemini-1.5-pro',
            'gemini-1.5-flash',
            'gemini-1.5-flash-8b',
            // Embedding models
            'text-embedding-004',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultModel(string $model): void
    {
        $this->defaultModel = $model;
    }

    /**
     * {@inheritdoc}
     */
    public function getRateLimitStatus(): array
    {
        return $this->rateLimitInfo;
    }

    /**
     * Set the API base URL (for custom endpoints).
     *
     * @param string $baseUrl Base URL
     * @return void
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Set request timeout.
     *
     * @param int $timeout Timeout in seconds
     * @return void
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Set the embedding model.
     *
     * @param string $model Embedding model identifier
     * @return void
     */
    public function setEmbeddingModel(string $model): void
    {
        $this->embeddingModel = $model;
    }

    /**
     * Build generation configuration from options.
     *
     * @param array $options User options
     * @return array Generation config
     */
    private function buildGenerationConfig(array $options): array
    {
        $config = [];

        if (isset($options['max_tokens'])) {
            $config['maxOutputTokens'] = $options['max_tokens'];
        } else {
            $config['maxOutputTokens'] = 8192;
        }

        if (isset($options['temperature'])) {
            $config['temperature'] = $options['temperature'];
        } else {
            $config['temperature'] = 0.7;
        }

        if (isset($options['top_p'])) {
            $config['topP'] = $options['top_p'];
        }

        if (isset($options['top_k'])) {
            $config['topK'] = $options['top_k'];
        }

        if (isset($options['stop_sequences'])) {
            $config['stopSequences'] = $options['stop_sequences'];
        }

        return $config;
    }

    /**
     * Convert schema to Gemini-compatible format.
     *
     * @param array $schema Input schema
     * @return array Gemini schema
     */
    private function convertToGeminiSchema(array $schema): array
    {
        // Gemini uses a specific schema format
        // This is a simplified conversion - extend as needed
        return [
            'type' => 'OBJECT',
            'properties' => $schema['properties'] ?? $schema,
        ];
    }

    /**
     * Clean JSON response from potential markdown formatting.
     *
     * @param string $content Response content
     * @return string Clean JSON
     */
    private function cleanJsonResponse(string $content): string
    {
        $content = trim($content);
        
        // Remove markdown code blocks
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/m', $content, $matches)) {
            $content = $matches[1];
        }
        
        // Remove leading/trailing backticks
        $content = trim($content, '`');
        
        return trim($content);
    }

    /**
     * Extract text content from Gemini response.
     *
     * @param array $response API response
     * @return string Extracted text
     */
    private function extractTextFromResponse(array $response): string
    {
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }

        // Handle potential safety blocks or empty responses
        if (isset($response['candidates'][0]['finishReason'])) {
            $reason = $response['candidates'][0]['finishReason'];
            if ($reason === 'SAFETY') {
                throw new \RuntimeException('Response blocked due to safety settings');
            }
        }

        return '';
    }

    /**
     * Make an API request to Gemini.
     *
     * @param string $model Model to use
     * @param string $method API method (generateContent, embedContent)
     * @param array $payload Request payload
     * @return array Response data
     * @throws \RuntimeException On API error
     */
    private function makeRequest(string $model, string $method, array $payload): array
    {
        $url = sprintf(
            '%s/models/%s:%s?key=%s',
            $this->baseUrl,
            $model,
            $method,
            $this->apiKey
        );
        
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("API request failed: {$error}");
        }
        
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';
            $errorCode = $data['error']['code'] ?? $httpCode;
            throw new \RuntimeException("Gemini API error ({$errorCode}): {$errorMessage}");
        }

        // Store rate limit info if available
        if (isset($data['usageMetadata'])) {
            $this->rateLimitInfo = [
                'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0,
            ];
        }
        
        return $data ?? [];
    }

    /**
     * Stream a completion response.
     *
     * @param string $prompt The prompt to send
     * @param callable $callback Callback for each chunk
     * @param array $options Options
     * @return void
     */
    public function streamComplete(string $prompt, callable $callback, array $options = []): void
    {
        $model = $options['model'] ?? $this->defaultModel;
        
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => $this->buildGenerationConfig($options),
        ];

        if (isset($options['system'])) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $options['system']]
                ]
            ];
        }

        $url = sprintf(
            '%s/models/%s:streamGenerateContent?key=%s',
            $this->baseUrl,
            $model,
            $this->apiKey
        );

        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // Remove "data: " prefix if present
                    if (str_starts_with($line, 'data: ')) {
                        $line = substr($line, 6);
                    }
                    
                    $chunk = json_decode($line, true);
                    if ($chunk && isset($chunk['candidates'][0]['content']['parts'][0]['text'])) {
                        $callback($chunk['candidates'][0]['content']['parts'][0]['text']);
                    }
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Stream request failed: {$error}");
        }
        
        curl_close($ch);
    }
}
