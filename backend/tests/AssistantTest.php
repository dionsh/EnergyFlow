<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Assistant\Grounding;
use EnergyFlow\Services\Assistant\GroqClient;
use EnergyFlow\Services\Assistant\Intents;
use EnergyFlow\Services\Assistant\Lang;
use EnergyFlow\Services\Assistant\MachineMatcher;
use EnergyFlow\Services\Assistant\Say;
use EnergyFlow\Services\Assistant\Snapshot;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;

// Three weeks of the demo story ending at 21:40 on a weekday: the compressor is
// running after hours, so there is something to switch off.
Clock::freeze(time());
$askAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($askAnchor);
$askDemo = DemoSeeder::reset($askAnchor, 21)['company_id'];
$askOwner = new Client('10.9.6.1');
assertSame(200, $askOwner->post('/auth/login', ['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'test-owner-password'])['status']);
$askMachine = static fn (string $code): int => (int) Database::value('SELECT id FROM machines WHERE company_id = ? AND code = ?', [$askDemo, $code]);

/** Asks in a fresh conversation; returns the assistant message. */
$askOne = static function (Client $client, string $question, array $context = [], string $language = 'en'): array {
    $conversation = $client->post('/assistant/conversations')['body']['data'];
    $response = $client->post("/assistant/conversations/{$conversation['id']}/messages", ['content' => $question, 'language' => $language, 'context' => $context]);
    assertSame(200, $response['status'], json_encode($response['body']));
    return $response['body']['data']['assistant'];
};

test('understands the common questions in English and Albanian, and leaves "why" to the model', function () use ($askDemo): void {
    $time = LocalTime::forCompany($askDemo);
    $now = Clock::now($askDemo);
    $intent = static fn (string $q): ?string => Intents::detect($q, $now, $time)['name'] ?? null;
    assertSame('top_consumer', $intent('Which machine cost us the most this month?'));
    assertSame('top_consumer', $intent('Cila makineri harxhon më shumë?'));
    assertSame('consumption', $intent('Sa konsumuam sot?'));
    assertSame('today', Intents::detect('Sa konsumuam sot?', $now, $time)['period']);
    assertSame('waste', $intent('How much did we waste after working hours this week?'));
    assertSame('after_hours', Intents::detect('How much did we waste after working hours this week?', $now, $time)['type']);
    assertSame('7d', Intents::detect('How much did we waste after working hours this week?', $now, $time)['period']);
    assertSame('opportunities', $intent('What should we change tomorrow?'));
    assertSame('opportunities', $intent('Çfarë duhet të ndryshojmë nesër?'));
    assertSame('alerts', $intent('A ka alarme?'));
    assertSame('savings', $intent('Sa kemi kursyer deri tani?'));
    assertSame('forecast', $intent('What is the month-end forecast?'));
    assertSame('turn_off', $intent('fik kompresorin'));
    assertSame('turn_off', $intent('Can you switch off CMP-01?'));
    assertSame('navigate:/carbon', $intent('hap karbonin'));
    assertSame('off_topic', $intent('Did Messi win yesterday?'));
    assertSame(null, $intent('Why was our bill higher in September?'), 'reasons go to the model');
    assertSame(null, $intent('Why didn\'t the compressor turn off?'), 'a question about a Turn Off is not a Turn Off');
    assertSame(null, $intent('What is Scope 2?'), 'definitions go to the model');
    assertSame('carbon', $intent('What is our CO2 this month?'), 'but our own CO₂ is data');
    assertSame('sq', Lang::detect('Sa konsumuam sot?', 'en'));
    assertSame('en', Lang::detect('Write me a python script', 'sq'));
});

test('general questions and advice go to the model; lookups stay deterministic', function () use ($askDemo): void {
    $time = LocalTime::forCompany($askDemo);
    $now = Clock::now($askDemo);
    $intent = static fn (string $q): ?string => Intents::detect($q, $now, $time)['name'] ?? null;
    assertSame(null, $intent('How much energy does a compressor typically use?'), 'a general question is not a lookup of our numbers');
    assertSame(null, $intent('How can a small business reduce electricity consumption?'));
    assertSame('consumption', $intent('How much energy did we use this month?'), 'ours stays a lookup');

    // "Based on our data, how can we…" still names a lookup (its fallback), but asks for advice.
    $combined = 'Based on our recent consumption, what changes could reduce our carbon emissions?';
    assertSame('carbon', $intent($combined));
    assertTrue(Intents::advisory($combined));
    assertTrue(Intents::advisory('Si mund ta ulim konsumin?'));
    assertTrue(!Intents::advisory('What should we change tomorrow?'), 'the ranked list answers this one');
    assertTrue(!Intents::advisory('Which machine cost us the most this month?'));

    assertTrue(Intents::general('What is ESG reporting and why does it matter for an SME?'));
    assertTrue(Intents::general('How much energy does a compressor typically use?'));
    assertTrue(!Intents::general('Why did our energy consumption increase this week?'));
    assertTrue(!Intents::general('What is our CO2 this month?'));

    assertTrue(!Intents::general('What is a carbon footprint and how is ours calculated?'), '"ours" is about us');
    assertSame(null, $intent('How do solar panels help a factory?'), '"actor" is not in "factory"');
    assertSame('off_topic', $intent('Who is the best actor?'));

    assertTrue(Intents::inScope('What is a carbon footprint?'));
    assertTrue(Intents::inScope('How do solar panels help a factory?'));
    assertTrue(!Intents::inScope('Who won the match yesterday?'));
});

test('without a language model, advice built on our data still gets the data answer', function () use ($askOwner, $askOne): void {
    $answer = $askOne($askOwner, 'Based on our recent consumption, what changes could reduce our carbon emissions?');
    assertSame('data', $answer['source']);
    assertSame('carbon', $answer['intent']);
});

test('three text models are tried: the configured ones, then the remaining defaults', function (): void {
    $saved = array_map(static fn (string $k): string|false => getenv($k), ['GROQ_MODEL', 'GROQ_MODEL_FALLBACK', 'GROQ_MODEL_FALLBACK_2']);
    try {
        putenv('GROQ_MODEL');
        putenv('GROQ_MODEL_FALLBACK');
        putenv('GROQ_MODEL_FALLBACK_2');
        assertSame(['openai/gpt-oss-120b', 'qwen/qwen3.8-27b', 'openai/gpt-oss-20b'], GroqClient::models());
        putenv('GROQ_MODEL_FALLBACK=openai/gpt-oss-20b'); // an older .env
        assertSame(['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'qwen/qwen3.8-27b'], GroqClient::models());
    } finally {
        foreach (['GROQ_MODEL', 'GROQ_MODEL_FALLBACK', 'GROQ_MODEL_FALLBACK_2'] as $i => $k) {
            putenv($saved[$i] === false ? $k : "{$k}={$saved[$i]}");
        }
    }
});

test('the conversation sent to the model leaves out answers the model never gave', function (): void {
    $turns = (new ReflectionMethod(\EnergyFlow\Services\Assistant\AssistantService::class, 'turns'))->invoke(null, [
        ['role' => 'user', 'content' => 'Which machine uses the most?', 'source' => null],
        ['role' => 'assistant', 'content' => 'IMM-01 uses the most.', 'source' => 'data'],
        ['role' => 'user', 'content' => 'Why?', 'source' => null],
        ['role' => 'assistant', 'content' => 'The language model is busy right now.', 'source' => 'fallback'],
        ['role' => 'user', 'content' => 'And last month?', 'source' => null],
        ['role' => 'assistant', 'content' => str_repeat('x', 900), 'source' => 'ai'],
    ]);
    assertSame(['Which machine uses the most?', 'IMM-01 uses the most.', 'And last month?'], array_slice(array_column($turns, 'content'), 0, 3));
    assertSame(700, mb_strlen($turns[3]['content']), 'long answers are shortened');
    assertSame(['user', 'assistant', 'user', 'assistant'], array_column($turns, 'role'));
});

test('finds machines by code, by name and by type in either language', function () use ($askDemo): void {
    $codes = static fn (string $q): array => array_column(MachineMatcher::find($askDemo, $q), 'code');
    assertSame(['CMP-01'], $codes('turn off cmp 1'));
    assertSame(['CMP-01'], $codes('fik kompresorin'));
    assertSame(['HVAC-01'], $codes('is the heat pump on?'), '"heat pump" is not the water pump');
    assertSame(['IMM-01', 'IMM-02'], $codes('the moulder'), 'two of a kind: the user must choose');
    assertSame([], $codes('how are we doing?'));
});

test('off-topic questions get the exact refusal, without any model call', function () use ($askOwner, $askOne): void {
    $refusal = $askOne($askOwner, 'Did Messi win yesterday?');
    assertSame('refusal', $refusal['source']);
    assertTrue(str_starts_with($refusal['content'], 'I can only help with your company\'s energy'));
    assertSame(3, count(array_filter($refusal['actions'], static fn (array $a): bool => $a['type'] === 'ask')), 'three suggested questions');
    $sq = $askOne($askOwner, 'Kush fitoi ndeshjen e futbollit?', [], 'sq');
    assertTrue(str_starts_with($sq['content'], 'Mund të ndihmoj vetëm'), $sq['content']);
    assertSame(null, Database::value("SELECT model FROM ai_messages WHERE id = ?", [$refusal['id']]));
});

test('data answers use the numbers the pages show', function () use ($askOwner, $askOne): void {
    $overview = $askOwner->get('/overview')['body']['data'];
    $answer = $askOne($askOwner, 'How much energy did we use this month?');
    assertSame('data', $answer['source']);
    assertTrue(str_contains($answer['content'], (new Say('en'))->kwh((float) $overview['month']['kwh'])), $answer['content']);
    assertSame('/', $answer['sources'][0]['to']);

    $top = $askOne($askOwner, 'Cila makineri harxhon më shumë?', [], 'sq');
    assertSame('sq', $top['language']);
    assertTrue(str_contains($top['content'], 'konsumatorët më të mëdhenj'));
    assertTrue(str_contains($top['content'], ' kWh'));
});

test('Turn Off is only ever a card to confirm; safety rules and roles still apply', function () use ($askOwner, $askOne, $askMachine, $askDemo): void {
    $card = $askOne($askOwner, 'turn off the compressor');
    $action = array_values(array_filter($card['actions'], static fn (array $a): bool => $a['type'] === 'turn_off'))[0] ?? null;
    assertTrue($action !== null, 'a confirmation card: ' . $card['content']);
    assertSame('pending', $action['state']);
    assertSame($askMachine('CMP-01'), $action['machine']['id']);
    assertSame(0, (int) Database::value("SELECT COUNT(*) FROM device_commands WHERE company_id = ? AND source = 'user'", [$askDemo]), 'nothing sent yet');

    // Yes: the same API as the Live button, then the card records it.
    $command = $askOwner->post('/machines/' . $askMachine('CMP-01') . '/commands', ['command' => 'turn_off', 'reason' => 'Requested via Ask EnergyFlow']);
    assertSame(201, $command['status'], json_encode($command['body']));
    $resolved = $askOwner->post("/assistant/messages/{$card['id']}/action", ['state' => 'confirmed', 'command_id' => $command['body']['data']['id']]);
    assertSame('confirmed', $resolved['body']['data']['actions'][0]['state']);
    assertSame($command['body']['data']['id'], $resolved['body']['data']['actions'][0]['command_id']);

    $critical = $askOne($askOwner, 'turn off OFF-01');
    assertSame([], array_filter($critical['actions'], static fn (array $a): bool => $a['type'] === 'turn_off'), 'critical machines get no card');
    assertTrue(str_contains($critical['content'], 'never switches it off'));

    $guest = new Client('10.9.6.2');
    $guest->post('/auth/demo');
    $viewer = $askOne($guest, 'turn off the chiller');
    assertSame([], array_filter($viewer['actions'], static fn (array $a): bool => $a['type'] === 'turn_off'), 'viewers get no card');
    assertSame(404, $guest->post("/assistant/messages/{$card['id']}/action", ['state' => 'cancelled'])['status'], 'nobody resolves someone else\'s card');
});

test('without a language model, open questions get an honest fallback', function () use ($askOwner, $askOne): void {
    $answer = $askOne($askOwner, 'Why was our bill higher than last month?');
    assertSame('fallback', $answer['source']);
    assertSame('llm_unavailable', $answer['intent']);
    assertTrue(count($answer['actions']) === 3, 'it offers the questions it can answer');
});

test('the shared demo guest keeps conversations per browser session', function () use ($askOne): void {
    $first = new Client('10.9.6.3');
    $first->post('/auth/demo');
    $second = new Client('10.9.6.4');
    $second->post('/auth/demo');
    $askOne($first, 'hello');
    $mine = $first->get('/assistant/conversations')['body']['data'];
    assertTrue(count($mine) >= 1);
    assertSame([], $second->get('/assistant/conversations')['body']['data'], 'another visitor sees nothing');
    assertSame(404, $second->get('/assistant/conversations/' . $mine[0]['id'])['status']);
    assertSame(204, $first->call('DELETE', '/assistant/conversations/' . $mine[0]['id'])['status']);
});

test('the snapshot loads what a question needs and keeps other machines out', function () use ($askDemo, $askMachine): void {
    assertTrue(in_array('monthly_history', Snapshot::sectionsFor($askDemo, 'Why was our bill higher than in August?', []), true));
    assertTrue(!in_array('monthly_history', Snapshot::sectionsFor($askDemo, 'What is Scope 2?', []), true));
    $facts = Snapshot::build($askDemo, ['machine_id' => $askMachine('IMM-02')], 'Explain this machine');
    foreach ($facts['open_recommendations'] ?? [] as $r) {
        assertSame('IMM-02', $r['machine']);
    }
    foreach ($facts['open_alerts'] ?? [] as $a) {
        assertSame('IMM-02', $a['machine']);
    }
    assertSame('IMM-02', $facts['context']['machine']['code']);

    // This week: the last 7 days per machine, without three months of history.
    $week = Snapshot::sectionsFor($askDemo, 'Why did our energy consumption increase this week?', []);
    assertTrue(in_array('last_7_days', $week, true));
    assertTrue(!in_array('monthly_history', $week, true));
    $facts = Snapshot::build($askDemo, [], 'Why did our energy consumption increase this week?');
    assertTrue($facts['last_7_days']['kwh'] > 0);
    $changes = array_map(static fn (array $m): float => abs($m['change_kwh']), $facts['last_7_days']['machines_by_largest_change']);
    $sorted = $changes;
    rsort($sorted);
    assertSame($sorted, $changes, 'largest change first');

    // A general question carries who the company is, none of its measurements.
    $general = Snapshot::build($askDemo, [], 'What is Scope 2?', false);
    assertSame(['company', 'now_local', 'notes'], array_keys($general));
});

test('grounding: percentages are always checked; derived numbers only from numbers that belong together', function (): void {
    $data = ['months' => [['month' => '2026-08', 'kwh' => 15450.0], ['month' => '2026-09', 'kwh' => 15679.0]], 'waste' => ['kwh' => 954.0, 'share' => 0.061]];
    assertSame(true, Grounding::check('Consumption rose by 229 kWh (+1.5%) to 15,679 kWh.', $data)['grounded'], 'difference of two months');
    assertSame(true, Grounding::check('Waste was 6.1% of consumption.', $data)['grounded']);
    assertSame(false, Grounding::check('A VSD typically saves 10–30%.', $data)['grounded'], 'an unsourced statistic');
    assertSame(false, Grounding::check('That is 14,496 kWh more.', $data)['grounded'], 'not a difference of related numbers');
    Clock::freeze(null);
});
