<?php

declare(strict_types=1);

/*
 * Checks the Groq setup the assistant, report narratives and Scan depend on:
 *   php bin/check-ai.php
 * Is GROQ_API_KEY set, does Groq accept it, is every configured model offered,
 * and does each one answer? The key itself is never printed. Exit code 0 when
 * the first text model answers.
 */

use EnergyFlow\Core\Env;
use EnergyFlow\Services\Assistant\GroqClient;

require dirname(__DIR__) . '/src/bootstrap.php';

$say = static fn (string $line) => fwrite(STDOUT, '[ai] ' . $line . PHP_EOL);
$key = Env::get('GROQ_API_KEY');
if ($key === null) {
    $say('GROQ_API_KEY is not set. Locally: add it to backend/.env. On Render: Environment → Add GROQ_API_KEY, then redeploy.');
    exit(1);
}
$say('GROQ_API_KEY is set (' . strlen($key) . ' characters).');

$curl = curl_init('https://api.groq.com/openai/v1/models');
curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$raw = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);
if ($raw === false) {
    $say('Could not reach api.groq.com: ' . $error);
    exit(1);
}
if ($status === 401 || $status === 403) {
    $say("Groq rejected the key (HTTP {$status}). Create a new one at https://console.groq.com/keys.");
    exit(1);
}
if ($status !== 200) {
    $say("Groq answered HTTP {$status} when listing models.");
    exit(1);
}
$offered = array_column(json_decode((string) $raw, true)['data'] ?? [], 'id');

$configured = [...GroqClient::models(), Env::get('GROQ_VISION_MODEL', 'qwen/qwen3.8-27b')];
$firstWorks = false;
foreach (array_values(array_unique($configured)) as $i => $model) {
    if (!in_array($model, $offered, true)) {
        $say("{$model}: NOT offered by Groq any more. Pick a current id from https://console.groq.com/docs/models.");
        continue;
    }
    $result = GroqClient::chat([['role' => 'user', 'content' => 'Reply with the single word OK.']], ['models' => [$model], 'max_tokens' => 20, 'timeout' => 20]);
    $say(sprintf('%s: %s (%d ms)', $model, $result['ok'] ? 'answers' : 'fails: ' . $result['error'], $result['latency_ms']));
    if ($i === 0) {
        $firstWorks = $result['ok'];
    }
}
exit($firstWorks ? 0 : 1);
