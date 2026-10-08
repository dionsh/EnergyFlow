<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

use EnergyFlow\Core\Env;

/**
 * Groq chat completions (OpenAI-compatible), called only from the backend: the
 * key lives in the server environment and never reaches the browser. Model ids
 * change, so they come from the environment (docs/01-research.md §3). On a rate
 * limit or outage the fallback model is tried, which has its own limits.
 */
final class GroqClient
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    public static function configured(): bool
    {
        return Env::get('GROQ_API_KEY') !== null;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array{temperature?: float, max_tokens?: int, json?: bool} $options
     * @return array{ok: bool, text: string, model: ?string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, error: ?string}
     */
    public static function chat(array $messages, array $options = []): array
    {
        $key = Env::get('GROQ_API_KEY');
        if ($key === null) {
            return self::failure('not_configured', 0);
        }
        $models = array_values(array_unique(array_filter([
            Env::get('GROQ_MODEL', 'openai/gpt-oss-120b'),
            Env::get('GROQ_MODEL_FALLBACK', 'openai/gpt-oss-20b'),
        ])));

        $started = microtime(true);
        $last = null;
        foreach ($models as $model) {
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
            }
            if ($options['json'] ?? false) {
                $payload['response_format'] = ['type' => 'json_object'];
            }
            $curl = curl_init(self::URL);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 25,
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
                $last = self::failure('http_' . $status . ': ' . mb_substr((string) ($json['error']['message'] ?? ''), 0, 160), $latency);
                if ($status === 429 || $status >= 500) {
                    continue; // try the fallback model
                }
                break;
            }
            return [
                'ok' => true,
                'text' => trim((string) ($json['choices'][0]['message']['content'] ?? '')),
                'model' => $model,
                'tokens_in' => isset($json['usage']['prompt_tokens']) ? (int) $json['usage']['prompt_tokens'] : null,
                'tokens_out' => isset($json['usage']['completion_tokens']) ? (int) $json['usage']['completion_tokens'] : null,
                'latency_ms' => $latency,
                'error' => null,
            ];
        }
        if ($last !== null) {
            error_log('[EnergyFlow] Groq: ' . $last['error']);
        }
        return $last ?? self::failure('no_model', 0);
    }

    private static function failure(string $error, int $latency): array
    {
        return ['ok' => false, 'text' => '', 'model' => null, 'tokens_in' => null, 'tokens_out' => null, 'latency_ms' => $latency, 'error' => $error];
    }
}
