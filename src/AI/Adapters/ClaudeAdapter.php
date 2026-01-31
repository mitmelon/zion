<?php
namespace ZionXMemory\AI\Adapters;

use ZionXMemory\Contracts\AIAdapterInterface;

/**
 * ClaudeAdapter - Anthropic Claude integration
 * Supports Claude 3, Vision, and extended context
 * 
 * @package ZionXMemory\AI\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class ClaudeAdapter extends BaseAIAdapter implements AIAdapterInterface {
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    
    public function configure(array $config): void {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'claude-sonnet-4-20250514';
        $this->baseUrl = $config['base_url'] ?? 'https://api.anthropic.com/v1';
        if (!empty($config['retry']) && is_array($config['retry'])) {
            $this->setRetryOptions($config['retry']);
        }
    }
    
    public function summarize(string $content, array $options): string {
        $prompt = $this->buildSummarizationPrompt($content, $options);
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));
        
        return $response['content'][0]['text'] ?? '';
    }
    
    public function extractEntities(string $content): array {
        $prompt = $this->buildEntitiesPrompt($content);
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));
        
        $text = $response['content'][0]['text'] ?? '[]';
        return json_decode($text, true) ?? [];
    }
    
    public function extractRelationships(string $content): array {
        $prompt = $this->buildRelationshipsPrompt($content);
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));
        
        $text = $response['content'][0]['text'] ?? '[]';
        return json_decode($text, true) ?? [];
    }

    public function extractStructure(string $content): array {
        $prompt = $this->buildStructurePrompt($content);

        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));

        $text = $response['content'][0]['text'] ?? '{}';
        $result = json_decode($text, true) ?? [];
        return [
            'entities' => $result['entities'] ?? [],
            'relations' => $result['relationships'] ?? []
        ];
    }

    /**
     * Override to optimize batch processing using single structure extraction calls
     */
    public function extractStructureBatch(array $contents): array {
        $results = [];
        foreach ($contents as $key => $content) {
            $results[$key] = $this->extractStructure($content);
        }
        return $results;
    }

    public function extractClaims(string $content): array {
        $prompt = $this->buildClaimsPrompt($content);

        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));

        $text = $response['content'][0]['text'] ?? '[]';
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
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
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));
        
        $text = $response['content'][0]['text'] ?? '{}';
        return json_decode($text, true) ?? ['min' => 0.3, 'max' => 0.7, 'mean' => 0.5];
    }
    
    public function processMultimodal(array $inputs): array {
        $content = [];
        foreach ($inputs as $input) {
            if ($input['type'] === 'image') {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $input['media_type'] ?? 'image/jpeg',
                        'data' => $input['data']
                    ]
                ];
            } else {
                $content[] = ['type' => 'text', 'text' => $input['content']];
            }
        }
        
        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $content]
            ]
        ]));
        
        return ['result' => $response['content'][0]['text'] ?? ''];
    }
    
    public function getModelInfo(): array {
        return [
            'provider' => 'anthropic',
            'model' => $this->model,
            'capabilities' => ['text', 'vision', 'extended_context']
        ];
    }

    public function detectContradiction(string $claim1, string $claim2): ?bool {
        $prompt = $this->buildContradictionPrompt($claim1, $claim2);

        $response = $this->callWithRetries(fn() => $this->makeRequest('messages', [
            'model' => $this->model,
            'max_tokens' => 64,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]));

        $text = $response['content'][0]['text'] ?? '';
        $respLower = strtolower(trim($text));
        if (str_contains($respLower, 'yes') || str_contains($respLower, 'contradict')) return true;
        if (str_contains($respLower, 'no') || str_contains($respLower, 'not contradictory')) return false;
        return null;
    }
    
    private function makeRequest(string $endpoint, array $data): array {
        $ch = curl_init("{$this->baseUrl}/{$endpoint}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01'
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true) ?? [];
    }
}