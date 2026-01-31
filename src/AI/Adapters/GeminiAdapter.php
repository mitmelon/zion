<?php
namespace ZionXMemory\AI\Adapters;

use ZionXMemory\Contracts\AIAdapterInterface;
use Gemini; // google-gemini-php client facade
use Gemini\Data\GenerationConfig;
use Gemini\Enums\ResponseMimeType;

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
 * @package ZionXMemory\AI\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class GeminiAdapter extends BaseAIAdapter implements AIAdapterInterface {
    private string $apiKey = '';
    private string $baseUrl = '';
    private string $model = 'gemini-2.0-flash';
    private int $timeout = 15;
    /** @var mixed|null */
    private $client = null;

    public function configure(array $config): void {
        $this->apiKey = $config['api_key'] ?? $config['apikey'] ?? $this->apiKey;
        $this->baseUrl = rtrim($config['base_url'] ?? $config['baseUrl'] ?? $this->baseUrl, "/");
        $this->model = $config['model'] ?? $this->model;
        $this->timeout = (int) ($config['timeout'] ?? $this->timeout);
        if (!empty($config['client'])) $this->client = $config['client'];
        if (!empty($config['base_url'])) {
            // If base_url provided, try create factory with base url
            try {
                $this->client = Gemini::factory()->withApiKey($this->apiKey)->withBaseUrl($this->baseUrl)->make();
            } catch (\Throwable $_) {
                // ignore; will fallback to simple client creation later
            }
        }
        if (!empty($config['retry']) && is_array($config['retry'])) {
            $this->setRetryOptions($config['retry']);
        }
    }

    public function summarize(string $content, array $options): string {
        $prompt = $options['prompt'] ?? $this->buildSummarizationPrompt($content, $options);

        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->generateContent($prompt));

        if (is_object($res) && method_exists($res, 'text')) {
            return (string) $res->text();
        }

        if (is_string($res)) return $res;

        return json_encode($res);
    }

    public function extractEntities(string $content): array {
        $prompt = $this->buildEntitiesPrompt($content);
        $config = new GenerationConfig(responseMimeType: ResponseMimeType::APPLICATION_JSON);
        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->withGenerationConfig($config)->generateContent($prompt));

        if (is_object($res) && method_exists($res, 'json')) {
            $json = $res->json(true);
            if (is_array($json)) return $json;
            if (is_string($json)) {
                $parsed = $this->tryParseJson($json);
                if (is_array($parsed)) return $parsed;
            }
        }

        if (is_object($res) && method_exists($res, 'text')) {
            $parsed = $this->tryParseJson($res->text());
            if (is_array($parsed)) return $parsed;
        }

        return [];
    }

    public function extractRelationships(string $content): array {
        $prompt = $this->buildRelationshipsPrompt($content);
        $config = new GenerationConfig(responseMimeType: ResponseMimeType::APPLICATION_JSON);
        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->withGenerationConfig($config)->generateContent($prompt));

        if (is_object($res) && method_exists($res, 'json')) {
            $json = $res->json(true);
            if (is_array($json)) return $json;
        }

        if (is_object($res) && method_exists($res, 'text')) {
            $parsed = $this->tryParseJson($res->text());
            if (is_array($parsed)) return $parsed;
        }

        return [];
    }

    public function extractStructure(string $content): array {
        $prompt = $this->buildStructurePrompt($content);
        $config = new GenerationConfig(responseMimeType: ResponseMimeType::APPLICATION_JSON);
        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->withGenerationConfig($config)->generateContent($prompt));

        $parsed = null;
        if (is_object($res) && method_exists($res, 'json')) $parsed = $res->json(true);
        if (!is_array($parsed) && is_object($res) && method_exists($res, 'text')) $parsed = $this->tryParseJson($res->text());

        if (is_array($parsed)) {
            return [
                'entities' => $parsed['entities'] ?? [],
                'relations' => $parsed['relationships'] ?? []
            ];
        }
        return ['entities' => [], 'relations' => []];
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
        $config = new GenerationConfig(responseMimeType: ResponseMimeType::APPLICATION_JSON);
        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->withGenerationConfig($config)->generateContent($prompt));

        $parsed = null;
        if (is_object($res) && method_exists($res, 'json')) {
            $parsed = $res->json(true);
        }

        if (!is_array($parsed) && is_object($res) && method_exists($res, 'text')) {
            $parsed = $this->tryParseJson($res->text());
        }

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
        $config = new GenerationConfig(responseMimeType: ResponseMimeType::APPLICATION_JSON);
        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->withGenerationConfig($config)->generateContent($prompt));

        $parsed = null;
        if (is_object($res) && method_exists($res, 'json')) $parsed = $res->json(true);
        if (!is_array($parsed) && is_object($res) && method_exists($res, 'text')) $parsed = $this->tryParseJson($res->text());

        if (is_array($parsed) && isset($parsed['min'], $parsed['max'], $parsed['mean'])) {
            return [
                'min' => (float)$parsed['min'],
                'max' => (float)$parsed['max'],
                'mean' => (float)$parsed['mean']
            ];
        }

        return ['min' => 0.0, 'max' => 1.0, 'mean' => 0.5];
    }

    public function detectContradiction(string $claim1, string $claim2): ?bool {
        $prompt = $this->buildContradictionPrompt($claim1, $claim2);
        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->generateContent($prompt));

        if (is_object($res) && method_exists($res, 'text')) {
            $text = (string)$res->text();
        } elseif (is_string($res)) {
            $text = $res;
        } else {
            $text = json_encode($res);
        }

        $respLower = strtolower(trim($text));
        if (str_contains($respLower, 'yes') || str_contains($respLower, 'contradict')) return true;
        if (str_contains($respLower, 'no') || str_contains($respLower, 'not contradictory')) return false;
        return null;
    }

    public function processMultimodal(array $inputs): array {
        $res = $this->callWithRetries(fn() => $this->client()->generativeModel(model: $this->model)->generateContent($inputs));
        if (is_object($res) && method_exists($res, 'text')) return ['result' => (string)$res->text()];
        if (is_string($res)) return ['result' => $res];
        return is_array($res) ? $res : ['result' => json_encode($res)];
    }

    public function getModelInfo(): array {
        return [
            'provider' => 'google',
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'client_present' => (bool) $this->client
        ];
    }

    /**
     * Send HTTP request to configured endpoint and return decoded response or raw string.
     * Returns array on JSON decode success, otherwise returns raw string.
     */
    /**
     * Return/create Gemini client instance.
     * @return mixed
     */
    private function client(): mixed {
        if ($this->client !== null) return $this->client;

        if (!empty($this->apiKey)) {
            try {
                $this->client = Gemini::client($this->apiKey);
            } catch (\Throwable $e) {
                // try alternative factory creation
                try {
                    $this->client = Gemini::factory()->withApiKey($this->apiKey)->make();
                } catch (\Throwable $_) {
                    throw new \RuntimeException('Gemini client not available: ' . $e->getMessage());
                }
            }
        } else {
            try {
                $this->client = Gemini::client('');
            } catch (\Throwable $e) {
                throw new \RuntimeException('Gemini client not available and no api key configured.');
            }
        }

        return $this->client;
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
