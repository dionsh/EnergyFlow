<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;

// Six weeks of history: enough for the 14-day forecast backtest (28 days before each day).
Clock::freeze(time());
$insightAnchor = DemoClock::sceneAnchor('weekday_evening');
Clock::freeze($insightAnchor);
$insightDemo = DemoSeeder::reset($insightAnchor, 45)['company_id'];
$insightGuest = new Client('10.9.4.1');
$insightGuest->post('/auth/demo');

test('EnergyFlow Score: six weighted parts from the measurements, with what to fix next', function () use ($insightGuest, $insightDemo): void {
    $response = $insightGuest->get('/score');
    assertSame(200, $response['status'], json_encode($response['body']));
    $s = $response['body']['data'];
    assertTrue($s['score'] >= 0 && $s['score'] <= 100);
    assertSame(['waste', 'schedule', 'health', 'peak', 'follow_through', 'coverage'], array_keys($s['parts']));
    assertTrue(abs(array_sum(array_column($s['parts'], 'weight')) - 1.0) < 1e-9, 'weights add up to 1');
    // The score is the weighted average of the parts that have a value.
    $parts = array_filter($s['parts'], static fn (array $p): bool => $p['value'] !== null);
    $expected = array_sum(array_map(static fn (array $p): float => $p['weight'] * $p['value'], $parts)) / array_sum(array_column($parts, 'weight'));
    assertTrue(abs($expected - $s['score']) < 0.2, "{$expected} vs {$s['score']}");
    assertTrue($s['parts']['waste']['detail']['kwh'] > 0, 'the demo story has waste');
    assertTrue($s['next'] !== null && isset($s['parts'][$s['next']['key']]));
    assertTrue($s['previous'] !== null && $s['change'] !== null, 'compared with the week before');
    // The analytics pipeline keeps an hourly history.
    assertTrue((int) Database::value("SELECT COUNT(*) FROM score_snapshots WHERE company_id = ? AND kind = 'energyflow'", [$insightDemo]) >= 1);
});

test('month-end forecast has a P10–P90 range from a backtest', function () use ($insightGuest): void {
    $p = $insightGuest->get('/overview')['body']['data']['projection'];
    assertTrue($p['range'] !== null, 'enough history for a range');
    assertTrue($p['range']['kwh_p10'] <= $p['kwh'] && $p['kwh'] <= $p['range']['kwh_p90']);
    assertTrue($p['range']['bill_p10'] <= $p['bill']['subtotal'] && $p['bill']['subtotal'] <= $p['range']['bill_p90']);
    assertTrue($p['backtest']['days'] >= 7 && $p['backtest']['wape'] >= 0 && $p['backtest']['wape'] < 0.5, json_encode($p['backtest']));
});

test('energy flow: productive + waste + not per machine = what the grid delivered', function () use ($insightGuest): void {
    $f = $insightGuest->get('/energy-flow')['body']['data'];
    assertTrue(abs($f['productive_kwh'] + $f['waste_kwh'] + $f['unmetered_kwh'] - $f['site_kwh']) < 1.0, json_encode(array_diff_key($f, ['machines' => 1, 'period' => 1])));
    assertTrue($f['waste_kwh'] > 0 && count($f['machines']) >= 8);
    foreach ($f['machines'] as $m) {
        assertTrue($m['waste_kwh'] <= $m['kwh'], "{$m['code']} wastes no more than it uses");
    }
});

test('the assistant answers the score and the forecast range from data', function () use ($insightGuest): void {
    $conversation = $insightGuest->post('/assistant/conversations')['body']['data']['id'];
    $score = $insightGuest->post("/assistant/conversations/{$conversation}/messages", ['content' => 'What is our EnergyFlow score?', 'language' => 'en'])['body']['data']['assistant'];
    assertSame('score', $score['intent']);
    assertTrue(str_contains($score['content'], '/ 100'));
    $forecast = $insightGuest->post("/assistant/conversations/{$conversation}/messages", ['content' => 'What is the month-end forecast?', 'language' => 'en'])['body']['data']['assistant'];
    assertTrue(str_contains($forecast['content'], 'Likely range'), $forecast['content']);
    Clock::freeze(null);
});
