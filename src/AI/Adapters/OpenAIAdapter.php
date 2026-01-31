<?php
namespace ZionXMemory\AI\Adapters;

use ZionXMemory\Contracts\AIAdapterInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;

/**
 * OpenAIAdapter - OpenAI integration
 * Supports GPT-4, GPT-4 Vision, and JSON mode
 * 
 * @package ZionXMemory\AI\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class OpenAIAdapter extends BaseAIAdapter implements AIAdapterInterface {
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private ?Client $client = null;
    
    public function configure(array $config): void {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        if (!empty($config['retry']) && is_array($config['retry'])) {
            $this->setRetryOptions($config['retry']);
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ]);
    }
    
    public function summarize(string $content, array $options): string {
        $prompt = $this->buildSummarizationPrompt($content, $options);
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a precise summarizer that preserves intent, contradictions, and rejected ideas.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.3
        ]));

        if (is_array($response)) return $response['choices'][0]['message']['content'] ?? '';
        return '';
    }
    
    public function extractEntities(string $content): array {
        $prompt = $this->buildEntitiesPrompt($content);
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ]));

        if (!is_array($response)) return [];
        $result = json_decode($response['choices'][0]['message']['content'] ?? '', true);
        return $result['entities'] ?? [];
    }

    public function extractEntitiesBatch(array $contents): array {
        $promises = [];
        foreach ($contents as $key => $content) {
            $prompt = $this->buildEntitiesPrompt($content);
            $promises[$key] = $this->client->postAsync('chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'response_format' => ['type' => 'json_object']
                ]
            ]);
        }

        $responses = Promise\Utils::settle($promises)->wait();
        $results = [];

        foreach ($responses as $key => $response) {
            if ($response['state'] === 'fulfilled') {
                try {
                    $body = json_decode($response['value']->getBody(), true);
                    $contentStr = $body['choices'][0]['message']['content'] ?? '{}';
                    $parsed = json_decode($contentStr, true);
                    $results[$key] = $parsed['entities'] ?? [];
                } catch (\Throwable $e) {
                    $results[$key] = [];
                }
            } else {
                $results[$key] = [];
            }
        }
        return $results;
    }
    
    public function extractRelationships(string $content): array {
        $prompt = $this->buildRelationshipsPrompt($content);
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ]));

        if (!is_array($response)) return [];
        $result = json_decode($response['choices'][0]['message']['content'] ?? '', true);
        return $result['relationships'] ?? [];
    }

    public function extractStructure(string $content): array {
        $prompt = $this->buildStructurePrompt($content);

        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ]));

        if (!is_array($response)) return ['entities' => [], 'relations' => []];
        $result = json_decode($response['choices'][0]['message']['content'] ?? '', true);
        return [
            'entities' => $result['entities'] ?? [],
            'relations' => $result['relationships'] ?? []
        ];
    }

    public function extractRelationshipsBatch(array $contents): array {
        $promises = [];
        foreach ($contents as $key => $content) {
            $prompt = $this->buildRelationshipsPrompt($content);
            $promises[$key] = $this->client->postAsync('chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'response_format' => ['type' => 'json_object']
                ]
            ]);
        }

        $responses = Promise\Utils::settle($promises)->wait();
        $results = [];

        foreach ($responses as $key => $response) {
            if ($response['state'] === 'fulfilled') {
                try {
                    $body = json_decode($response['value']->getBody(), true);
                    $contentStr = $body['choices'][0]['message']['content'] ?? '{}';
                    $parsed = json_decode($contentStr, true);
                    $results[$key] = $parsed['relationships'] ?? [];
                } catch (\Throwable $e) {
                    $results[$key] = [];
                }
            } else {
                $results[$key] = [];
            }
        }
        return $results;
    }

    public function extractStructureBatch(array $contents): array {
        $promises = [];
        foreach ($contents as $key => $content) {
            $prompt = $this->buildStructurePrompt($content);
            $promises[$key] = $this->client->postAsync('chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'response_format' => ['type' => 'json_object']
                ]
            ]);
        }

        $responses = Promise\Utils::settle($promises)->wait();
        $results = [];

        foreach ($responses as $key => $response) {
            if ($response['state'] === 'fulfilled') {
                try {
                    $body = json_decode($response['value']->getBody(), true);
                    $contentStr = $body['choices'][0]['message']['content'] ?? '{}';
                    $parsed = json_decode($contentStr, true);
                    $results[$key] = [
                        'entities' => $parsed['entities'] ?? [],
                        'relations' => $parsed['relationships'] ?? []
                    ];
                } catch (\Throwable $e) {
                    $results[$key] = ['entities' => [], 'relations' => []];
                }
            } else {
                $results[$key] = ['entities' => [], 'relations' => []];
            }
        }
        return $results;
    }

    public function extractClaims(string $content): array {
        $prompt = $this->buildClaimsPrompt($content);

        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ]));

        if (!is_array($response)) return [];
        $text = $response['choices'][0]['message']['content'] ?? '';
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            // If the model returned objects with 'text' fields, map to strings
            $out = [];
            foreach ($parsed as $item) {
                if (is_string($item)) $out[] = $item;
                elseif (is_array($item) && isset($item['text'])) $out[] = $item['text'];
                else $out[] = is_string($item) ? $item : json_encode($item);
            }
            return array_values(array_unique($out));
        }

        return [];
    }
    
    public function scoreEpistemicConfidence(string $claim, array $context): array {
        $prompt = $this->buildScorePrompt($claim, $context);
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ]));

        if (!is_array($response)) return ['min' => 0.0, 'max' => 1.0, 'mean' => 0.5];
        return json_decode($response['choices'][0]['message']['content'] ?? '', true);
    }
    
    public function processMultimodal(array $inputs): array {
        // GPT-4 Vision support
        $messages = [];
        foreach ($inputs as $input) {
            if ($input['type'] === 'image') {
                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => $input['url']]]
                    ]
                ];
            } else {
                $messages[] = ['role' => 'user', 'content' => $input['content']];
            }
        }
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => 'gpt-4-vision-preview',
            'messages' => $messages
        ]));

        return [
            'result' => is_array($response) ? ($response['choices'][0]['message']['content'] ?? '') : ''
        ];
    }
    
    public function getModelInfo(): array {
        return [
            'provider' => 'openai',
            'model' => $this->model,
            'capabilities' => ['text', 'vision', 'json_mode']
        ];
    }
    
    private function makeRequest(string $endpoint, array $data): array {
        if (!$this->client) {
             // Fallback if configure wasn't called (though it should be)
             $this->client = new Client([
                'base_uri' => $this->baseUrl . '/',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 30
            ]);
        }

        try {
            $response = $this->client->post($endpoint, ['json' => $data]);
            return json_decode($response->getBody(), true) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function detectContradiction(string $claim1, string $claim2): ?bool {
        $prompt = $this->buildContradictionPrompt($claim1, $claim2);

        $response = $this->callWithRetries(fn() => $this->makeRequest('chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0
        ]));

        $text = is_array($response) ? ($response['choices'][0]['message']['content'] ?? '') : '';
        $respLower = strtolower(trim($text));
        if (str_contains($respLower, 'yes') || str_contains($respLower, 'contradict')) return true;
        if (str_contains($respLower, 'no') || str_contains($respLower, 'not contradictory')) return false;
        return null;
    }
}
