<?php
namespace ZionXMemory\AI\Adapters;

/**
 * BaseAIAdapter
 * Provides canonical prompt builders used across adapters to ensure consistent
 * prompts and expected responses.
 * 
 * @package ZionXMemory\AI\Adapters
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class BaseAIAdapter {

    protected int $retryMaxAttempts = 3;
    protected int $retryBaseDelayMs = 200;

    protected function buildSummarizationPrompt(string $content, array $options = []): string {
        $prompt = "Summarize the following content:\n\n{$content}\n\n";

        if ($options['preserve_intent'] ?? false) {
            $prompt .= "Preserve the original intent and reasoning.\n";
        }

        if ($options['preserve_contradictions'] ?? false) {
            $prompt .= "Explicitly preserve any contradictions or conflicting information.\n";
        }

        if ($options['preserve_rejected_ideas'] ?? false) {
            $prompt .= "Include ideas that were considered but rejected, with reasons.\n";
        }

        if ($options['delta_mode'] ?? false) {
            $prompt .= "Focus only on what changed compared to: " . ($options['previous_summary'] ?? '') . "\n";
        }

        return $prompt;
    }

    protected function buildEntitiesPrompt(string $content): string {
        return "Extract all entities from the following text. Return as JSON array with format: [{\"name\": \"...\", \"type\": \"...\", \"attributes\": {...}}]\n\n{$content}";
    }

    protected function buildRelationshipsPrompt(string $content): string {
        return "Extract relationships from the text. Return as JSON: [{\"from\": \"...\", \"from_type\": \"...\", \"to\": \"...\", \"to_type\": \"...\", \"type\": \"...\", \"confidence\": 0.0-1.0}]\n\n{$content}";
    }

    /**
     * Default sequential implementation for batch entity extraction
     */
    public function extractEntitiesBatch(array $contents): array {
        $results = [];
        foreach ($contents as $key => $content) {
            $results[$key] = $this->extractEntities($content);
        }
        return $results;
    }

    /**
     * Default sequential implementation for batch relationship extraction
     */
    public function extractRelationshipsBatch(array $contents): array {
        $results = [];
        foreach ($contents as $key => $content) {
            $results[$key] = $this->extractRelationships($content);
        }
        return $results;
    }

    protected function buildClaimsPrompt(string $content): string {
        return "Extract explicit claims and factual statements from the following text. Return as a JSON array of strings or objects describing each claim (e.g. [{\"text\": \"...\", \"confidence\": 0.0-1.0}]). Prefer short, self-contained declarative sentences.\n\n{$content}";
    }

    protected function buildScorePrompt(string $claim, array $context = []): string {
        return "Analyze the epistemic confidence for this claim. Return JSON: {\"min\": 0.0-1.0, \"max\": 0.0-1.0, \"mean\": 0.0-1.0, \"reasoning\": \"...\"}\n\nClaim: {$claim}";
    }

    protected function buildContradictionPrompt(string $claim1, string $claim2): string {
        return "Determine whether the following two claims contradict each other. Respond with a single word: 'yes' or 'no'.\n\nClaim 1: \"" . trim($claim1) . "\"\nClaim 2: \"" . trim($claim2) . "\"";
    }

    protected function setRetryOptions(array $opts): void {
        if (isset($opts['max_attempts']) && is_int($opts['max_attempts']) && $opts['max_attempts'] > 0) {
            $this->retryMaxAttempts = $opts['max_attempts'];
        }
        if (isset($opts['base_delay_ms']) && is_int($opts['base_delay_ms']) && $opts['base_delay_ms'] > 0) {
            $this->retryBaseDelayMs = $opts['base_delay_ms'];
        }
    }

    /* Call a function with retry/backoff when responses are null/empty.
    * Uses exponential backoff with jitter. Returns the last result.
    *
    * Set retry options for adapters. Example: ['max_attempts' => 5, 'base_delay_ms' => 300]

    * @param callable $fn function(): mixed
    * @param int $maxAttempts
    * @param int $baseDelayMs
    */
    protected function callWithRetries(callable $fn, ?int $maxAttempts = null, ?int $baseDelayMs = null): mixed {
        $maxAttempts = $maxAttempts ?? $this->retryMaxAttempts;
        $baseDelayMs = $baseDelayMs ?? $this->retryBaseDelayMs;

        $attempt = 0;
        $last = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $res = $fn();
            } catch (\Throwable $e) {
                $res = null;
            }

            $last = $res;

            if ($this->isValidResponse($res)) {
                return $res;
            }

            if ($attempt < $maxAttempts) {
                // exponential backoff with jitter
                $delay = $baseDelayMs * (2 ** ($attempt - 1));
                try {
                    $jitter = random_int(0, (int)($baseDelayMs / 2));
                } catch (\Throwable $_) {
                    $jitter = mt_rand(0, (int)($baseDelayMs / 2));
                }
                $sleepMs = $delay + $jitter;
                usleep($sleepMs * 1000);
            }
        }

        return $last;
    }

    protected function isValidResponse(mixed $res): bool {
        if ($res === null) return false;
        if (is_string($res) && trim($res) === '') return false;
        if (is_array($res) && empty($res)) return false;
        return true;
    }
}
