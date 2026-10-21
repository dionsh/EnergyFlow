<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

use EnergyFlow\Core\Env;

/**
 * Groq chat completions (OpenAI-compatible), called only from the backend: the
 * key lives in the server environment and never reaches the browser. Model ids
 * change, so they come from the environment (docs/01-research.md §3).
 *
 * Each model has its own free-tier limits (8,000 tokens per minute on the free
 * plan), so the chain GROQ_MODEL → GROQ_MODEL_FALLBACK → GROQ_MODEL_FALLBACK_2
 * is tried in order when a model is rate-limited, down or no longer offered.
 * A rejected key stops the chain: every model would reject it.
 */
final class GroqClient
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';
    /** When every model is rate-limited, wait this long at most (Groq's retry-after) and try the soonest one again. */
    private const MAX_WAIT_S = 8;
    /** Text models in order of preference (checked against Groq's model list, Oct 2026). */
    private const DEFAULT_MODELS = ['openai/gpt-oss-120b', 'qwen/qwen3.8-27b', 'openai/gpt-oss-20b'];

    public static function configured(): bool
    {
        return Env::get('GROQ_API_KEY') !== null;
    }

    /** The text models, in the order they are tried: each unset slot takes the best default no other slot uses. */
    public static function models(): array
    {
        $slots = ['GROQ_MODEL', 'GROQ_MODEL_FALLBACK', 'GROQ_MODEL_FALLBACK_2'];
        $configured = array_filter(array_map(static fn (string $key): ?string => Env::get($key), $slots));
        $chain = [];
        foreach ($slots as $key) {
            $chain[] = Env::get($key) ?? current(array_diff(self::DEFAULT_MODELS, $chain, $configured)) ?: null;
        }
        return array_values(array_unique(array_filter($chain)));
    }

    /**
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages content may mix text and images
     * @param array{temperature?: float, max_tokens?: int, json?: bool, models?: list<string>, timeout?: int} $options
     * @return array{ok: bool, text: string, model: ?string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, error: ?string}
     *         error starts with not_configured, auth, rate_limited, network or http_<status>
     */
    public static function chat(array $messages, array $options = []): array
    {
        $key = Env::get('GROQ_API_KEY');
        if ($key === null) {
            return self::failure('not_configured', 0);
        }
        $models = array_values(array_unique(array_filter($options['models'] ?? self::models())));

        $started = microtime(true);
        $last = null;
        $waits = [];
        $queue = $models;
        while (($model = array_shift($queue)) !== null) {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? 0.2,
                'max_tokens' => $options['max_tokens'] ?? 700,
            ];
            if (str_starts_with($model, 'openai/gpt-oss')) {
                // Reasoning models: keep the thinking short (cost, latency, free-tier limits) and leave room for the answer.
                $payload['reasoning_effort'] = 'low';
                $payload['max_tokens'] += 800;
            } elseif (str_starts_with($model, 'qwen/') && !self::hasImage($messages)) {
                $payload['reasoning_effort'] = 'none'; // a direct answer; thinking would only spend the token budget
            }
            if ($options['json'] ?? false) {
                $payload['response_format'] = ['type' => 'json_object'];
            }
            $retryAfter = null;
            $curl = curl_init(self::URL);
            curl_setopt_array($curl, [
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$retryAfter): int {
                    if (stripos($header, 'retry-after:') === 0) {
                        $retryAfter = (float) trim(substr($header, 12));
                    }
                    return strlen($header);
                },
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $options['timeout'] ?? 25,
            ]);
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);
            $latency = (int) round((microtime(true) - $started) * 1000);

            if ($raw === false) {
                $last = self::failure('network: ' . $curlError, $latency);
                continue;
            }
            $json = json_decode((string) $raw, true);
            if ($status !== 200) {
                $detail = mb_substr((string) ($json['error']['message'] ?? ''), 0, 160);
                $last = self::failure(match (true) {
                    $status === 401 || $status === 403 => 'auth',
                    $status === 429 => 'rate_limited',
                    default => 'http_' . $status,
                } . ': ' . $detail, $latency);
                error_log('[EnergyFlow] Groq ' . $model . ': ' . $last['error']);
                if ($status === 401 || $status === 403) {
                    break; // the key itself was refused
                }
                if ($status === 429 && $retryAfter !== null && !array_key_exists($model, $waits)) {
                    $waits[$model] = $retryAfter;
                }
                if ($queue === [] && $waits !== [] && min($waits) <= self::MAX_WAIT_S) {
                    // Every model is busy; the free tier refills within seconds, so one short wait usually beats giving up.
                    asort($waits);
                    $soonest = array_key_first($waits);
                    usleep((int) (max(0.5, $waits[$soonest]) * 1_000_000));
                    $waits = array_fill_keys(array_keys($waits), PHP_INT_MAX); // once only
                    $queue = [$soonest];
                }
                continue; // rate limit, outage, retired model: the next model has its own limits
            }
            $text = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
            if ($text === '') {
                $last = self::failure('empty_answer', $latency);
                continue;
            }
            return [
                'ok' => true,
                'text' => $text,
                'model' => $model,
                'tokens_in' => isset($json['usage']['prompt_tokens']) ? (int) $json['usage']['prompt_tokens'] : null,
                'tokens_out' => isset($json['usage']['completion_tokens']) ? (int) $json['usage']['completion_tokens'] : null,
                'latency_ms' => $latency,
                'error' => null,
            ];
        }
        return $last ?? self::failure('no_model', 0);
    }

    private static function hasImage(array $messages): bool
    {
        foreach ($messages as $message) {
            if (is_array($message['content'])) {
                return true;
            }
        }
        return false;
    }

    private static function failure(string $error, int $latency): array
    {
        return ['ok' => false, 'text' => '', 'model' => null, 'tokens_in' => null, 'tokens_out' => null, 'latency_ms' => $latency, 'error' => $error];
    }
}
