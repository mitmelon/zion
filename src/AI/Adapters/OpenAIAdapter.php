<?php
namespace ZionXMemory\AI;

use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\AI\Adapters\BaseAIAdapter;

/**
 * OpenAIAdapter - OpenAI integration
 * Supports GPT-4, GPT-4 Vision, and JSON mode
 * 
 * @package ZionXMemory\AI
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class OpenAIAdapter extends BaseAIAdapter implements AIAdapterInterface {
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    
    public function configure(array $config): void {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4';
        $this->baseUrl = $config['base_url'] ?? 'https://api.openai.com/v1';
        if (!empty($config['retry']) && is_array($config['retry'])) {
            $this->setRetryOptions($config['retry']);
        }
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
    
    // Summarization prompt builder is provided by BaseAIAdapter
    
    private function makeRequest(string $endpoint, array $data): array {
        $ch = curl_init("{$this->baseUrl}/{$endpoint}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true) ?? [];
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