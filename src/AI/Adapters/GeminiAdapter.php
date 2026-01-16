<?php
namespace ZionXMemory\AI\Adapters;

use ZionXMemory\Contracts\AIAdapterInterface;

/**
 * GeminiAdapter - Google Gemini integration
 * Configuration keys supported:
 * - api_key: string
 * - base_url: string (default: empty)
 * - model: string (default: 'gemini')
 * - timeout: int seconds (default: 15)
 * - endpoint: string (path to generate endpoint, default '/v1/generate')
 *
 * This adapter keeps no hard dependency on external SDKs and uses cURL.
 * 
 * @package ZionXMemory\AI
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GeminiAdapter extends BaseAIAdapter implements AIAdapterInterface {
    private string $apiKey = '';
    private string $baseUrl = '';
    private string $model = 'gemini';
    private int $timeout = 15;
    private string $endpoint = '/v1/generate';

    public function configure(array $config): void {
        $this->apiKey = $config['api_key'] ?? $config['apikey'] ?? $this->apiKey;
        $this->baseUrl = rtrim($config['base_url'] ?? $config['baseUrl'] ?? $this->baseUrl, "/");
        $this->model = $config['model'] ?? $this->model;
        $this->timeout = (int) ($config['timeout'] ?? $this->timeout);
        $this->endpoint = $config['endpoint'] ?? $this->endpoint;
        if (!empty($config['retry']) && is_array($config['retry'])) {
            $this->setRetryOptions($config['retry']);
        }
    }

    public function summarize(string $content, array $options): string {
        $prompt = $options['prompt'] ?? $this->buildSummarizationPrompt($content, $options);
        $payload = [
            'model' => $this->model,
            'input' => $prompt,
            'options' => $options
        ];

        $resp = $this->callWithRetries(fn() => $this->request($this->endpoint, $payload));

        if (is_array($resp)) {
            // Try to find text in common fields
            if (isset($resp['output'])) {
                return is_string($resp['output']) ? $resp['output'] : json_encode($resp['output']);
            }
            if (isset($resp['text'])) return (string) $resp['text'];
            if (isset($resp['choices'][0]['text'])) return (string) $resp['choices'][0]['text'];
            return json_encode($resp);
        }

        return (string) $resp;
    }

    public function extractEntities(string $content): array {
        $prompt = $this->buildEntitiesPrompt($content);
        
        $payload = ['model' => $this->model, 'input' => $prompt, 'options' => ['response_format' => 'json']];
        $resp = $this->callWithRetries(fn() => $this->request($this->endpoint, $payload));

        if (is_array($resp)) {
            // if model returned a parsed entities field
            if (isset($resp['entities']) && is_array($resp['entities'])) return $resp['entities'];
            // try to parse text as JSON
            $text = $this->extractTextFromResponse($resp);
            $parsed = $this->tryParseJson($text);
            if (is_array($parsed)) return $parsed;
        }

        return [];
    }

    public function extractRelationships(string $content): array {
        $prompt = $this->buildRelationshipsPrompt($content);
        
        $payload = ['model' => $this->model, 'input' => $prompt, 'options' => ['response_format' => 'json']];
        $resp = $this->callWithRetries(fn() => $this->request($this->endpoint, $payload));

        if (is_array($resp)) {
            if (isset($resp['relationships']) && is_array($resp['relationships'])) return $resp['relationships'];
            $text = $this->extractTextFromResponse($resp);
            $parsed = $this->tryParseJson($text);
            if (is_array($parsed)) return $parsed;
        }

        return [];
    }

    public function extractClaims(string $content): array {
        $prompt = $this->buildClaimsPrompt($content);

        $payload = ['model' => $this->model, 'input' => $prompt, 'options' => ['response_format' => 'json']];
        $resp = $this->callWithRetries(fn() => $this->request($this->endpoint, $payload));

        if (is_array($resp)) {
            if (isset($resp['claims']) && is_array($resp['claims'])) return $resp['claims'];
            $text = $this->extractTextFromResponse($resp);
            $parsed = $this->tryParseJson($text);
            if (is_array($parsed)) {
                // If returned as array of objects, map to strings
                $out = [];
                foreach ($parsed as $item) {
                    if (is_string($item)) $out[] = $item;
                    elseif (is_array($item) && isset($item['text'])) $out[] = $item['text'];
                    else $out[] = is_string($item) ? $item : json_encode($item);
                }
                return array_values(array_unique($out));
            }
        }

        return [];
    }

    public function scoreEpistemicConfidence(string $claim, array $context): array {
        $prompt = $this->buildScorePrompt($claim, $context);

            $payload = ['model' => $this->model, 'input' => $prompt, 'options' => ['response_format' => 'json', 'temperature' => 0]];
            $resp = $this->callWithRetries(fn() => $this->request($this->endpoint, $payload));

        if (is_array($resp)) {
            $text = $this->extractTextFromResponse($resp);
            $parsed = $this->tryParseJson($text);
            if (is_array($parsed) && isset($parsed['min'], $parsed['max'], $parsed['mean'])) {
                return [
                    'min' => (float)$parsed['min'],
                    'max' => (float)$parsed['max'],
                    'mean' => (float)$parsed['mean']
                ];
            }
        }

        // Conservative default if model unavailable
        return ['min' => 0.0, 'max' => 1.0, 'mean' => 0.5];
    }

    public function detectContradiction(string $claim1, string $claim2): ?bool {
        $prompt = $this->buildContradictionPrompt($claim1, $claim2);

            $payload = ['model' => $this->model, 'input' => $prompt, 'options' => ['max_tokens' => 16, 'temperature' => 0]];
            $resp = $this->callWithRetries(fn() => $this->request($this->endpoint, $payload));

        if (is_array($resp)) {
            $text = $this->extractTextFromResponse($resp);
        } else {
            $text = (string)$resp;
        }

        $respLower = strtolower(trim($text));
        if (str_contains($respLower, 'yes') || str_contains($respLower, 'contradict')) return true;
        if (str_contains($respLower, 'no') || str_contains($respLower, 'not contradictory')) return false;
        return null;
    }

    public function processMultimodal(array $inputs): array {
        $payload = ['model' => $this->model, 'input' => $inputs, 'options' => ['multimodal' => true]];
            $resp = $this->callWithRetries(fn() => $this->request($this->endpoint, $payload));
        return is_array($resp) ? $resp : ['result' => (string)$resp];
    }

    public function getModelInfo(): array {
        return [
            'provider' => 'google',
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'endpoint' => $this->endpoint
        ];
    }

    /**
     * Send HTTP request to configured endpoint and return decoded response or raw string.
     * Returns array on JSON decode success, otherwise returns raw string.
     */
    private function request(string $path, array $payload): mixed {
        $url = $this->baseUrl ? $this->baseUrl . $path : $path;

        $ch = curl_init();
        $body = json_encode($payload);

        $headers = ['Content-Type: application/json'];
        if (!empty($this->apiKey)) $headers[] = 'Authorization: Bearer ' . $this->apiKey;

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $resp === null) {
            return ['error' => 'curl_error', 'message' => $err, 'code' => $code];
        }

        // Try decode as JSON
        $decoded = $this->tryParseJson($resp);
        if (is_array($decoded)) return $decoded;

        // Otherwise return raw string
        return $resp;
    }

    private function tryParseJson(string $s): mixed {
        $sTrim = trim($s);
        if ($sTrim === '') return null;
        $decoded = json_decode($sTrim, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        return null;
    }

    private function extractTextFromResponse(array $resp): string {
        if (isset($resp['text']) && is_string($resp['text'])) return $resp['text'];
        if (isset($resp['output']) && is_string($resp['output'])) return $resp['output'];
        if (isset($resp['choices'][0]['text'])) return (string)$resp['choices'][0]['text'];
        return json_encode($resp);
    }
}
