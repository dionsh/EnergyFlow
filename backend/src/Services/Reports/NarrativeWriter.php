<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Reports;

use EnergyFlow\Services\Assistant\GroqClient;
use EnergyFlow\Services\Assistant\Grounding;

/**
 * The three narrative sections of a report: summary, what changed, outlook.
 *
 * The LLM only writes prose over the frozen numbers (docs/03 §9.1) and every
 * number it writes is checked against them. If Groq isn't configured, fails, or
 * writes a number that isn't in the data, a deterministic template is used —
 * so a report never depends on the LLM and never shows an invented figure.
 */
final class NarrativeWriter
{
    /** What the "previous" figures cover (snapshot previous.basis). */
    private const BASIS = [
        'en' => ['previous_day' => 'the same hours of the previous day', 'previous_week' => 'the same days of the previous week', 'previous_month' => 'the same days of the previous month'],
        'sq' => ['previous_day' => 'të njëjtat orë të ditës së kaluar', 'previous_week' => 'të njëjtat ditë të javës së kaluar', 'previous_month' => 'të njëjtat ditë të muajit të kaluar'],
    ];

    /** @return array{sections: array{summary: string, changes: string, outlook: string}, source: string} */
    public static function write(array $snapshot, string $language): array
    {
        $language = $language === 'sq' ? 'sq' : 'en';
        $facts = self::facts($snapshot, $language);
        if (GroqClient::configured()) {
            $ai = self::ai($facts, $language);
            if ($ai !== null) {
                return ['sections' => $ai, 'source' => 'ai'];
            }
        }
        return ['sections' => self::template($snapshot, $language), 'source' => 'template'];
    }

    /** The compact, plain facts the model may use (and the grounding check compares against). */
    private static function facts(array $s, string $language): array
    {
        return [
            'company' => $s['company']['name'],
            'period' => $s['period']['month'],
            'partial_period' => $s['period']['partial'],
            'energy_kwh' => $s['energy']['kwh'],
            'energy_cost_eur_excl_vat' => $s['energy']['eur'],
            'peak_kw' => $s['energy']['peak_kw'],
            'co2e_kg_scope2_location' => $s['carbon']['scope2_location_kg'],
            'previous' => [
                'compared_with' => self::BASIS['en'][$s['previous']['basis'] ?? 'previous_month'],
                'energy_kwh' => $s['previous']['kwh'], 'cost_eur' => $s['previous']['eur'], 'change_ratio' => $s['previous']['change_ratio'],
            ],
            'waste' => [
                'kwh' => $s['waste']['kwh'], 'eur' => $s['waste']['eur'], 'co2e_kg' => $s['waste']['co2_kg'], 'share_of_consumption' => $s['waste']['share'],
                'largest' => $s['waste']['largest'] === null ? null : [
                    'machine' => $s['waste']['largest']['name'], 'type' => $s['waste']['largest']['type'],
                    'kwh' => $s['waste']['largest']['kwh'], 'eur' => $s['waste']['largest']['eur'],
                ],
            ],
            'verified_turn_offs' => ['manual' => $s['actions']['turn_offs_manual'], 'automatic' => $s['actions']['turn_offs_automatic']],
            'verified_savings_to_date' => $s['impact']['verified'],
            'top_machines' => array_map(static fn (array $m): array => ['machine' => $m['name'], 'kwh' => $m['kwh'], 'share' => $m['share']], array_slice($s['machines'], 0, 3)),
            'open_opportunities' => array_map(static fn (array $r): array => [
                'action' => self::action($r, $language), 'eur_per_month' => $r['eur_month'], 'kwh_per_month' => $r['kwh_month'], 'cost_only' => $r['impact_tag'] === 'eur',
            ], array_slice($s['recommendations'], 0, 3)),
            'open_opportunities_total_eur_per_month' => round(array_sum(array_column($s['recommendations'], 'eur_month')), 2),
        ];
    }

    private static function ai(array $facts, string $language): ?array
    {
        $name = $language === 'sq' ? 'Albanian (Shqip, Kosovo usage)' : 'English';
        $system = <<<PROMPT
You write the narrative of an energy and sustainability report for a small manufacturer in Kosovo.
Write in {$name}. Use ONLY the facts and numbers in the JSON the user sends. Never invent a number, a cause, a comparison or a recommendation.
Plain, specific sentences for a business owner; euros first; no marketing language, no emojis, no headings.
Write CO2e as "CO₂e". Round sensibly (kWh without decimals, euros with two).
Return a JSON object with exactly these keys:
- "summary": 3-4 sentences: energy, cost (excl. VAT), CO₂e, waste and verified savings to date.
- "changes": 2-3 sentences: change vs the comparison window (say which one, from previous.compared_with), and the largest waste event.
- "outlook": 2-3 sentences: the most valuable open opportunities and their total per month. Say if one saves cost only.
PROMPT;
        $result = GroqClient::chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE)],
        ], ['temperature' => 0.2, 'max_tokens' => 700, 'json' => true]);
        if (!$result['ok']) {
            return null;
        }
        $json = json_decode($result['text'], true);
        if (!is_array($json) || !isset($json['summary'], $json['changes'], $json['outlook'])) {
            return null;
        }
        $sections = ['summary' => trim((string) $json['summary']), 'changes' => trim((string) $json['changes']), 'outlook' => trim((string) $json['outlook'])];
        $check = Grounding::check(implode("\n", $sections), $facts);
        if (!$check['grounded']) {
            error_log('[EnergyFlow] report narrative rejected, unverified numbers: ' . implode(', ', $check['unmatched']));
            return null;
        }
        return $sections;
    }

    /** Deterministic narrative from the same facts. */
    private static function template(array $s, string $language): array
    {
        $en = $language === 'en';
        $n = static fn (float $v, int $d = 0): string => $en ? number_format($v, $d, '.', ',') : number_format($v, $d, ',', ' ');
        $eur = static fn (float $v): string => $en ? '€' . $n($v, 2) : $n($v, 2) . ' €';
        $co2 = static fn (float $kg): string => $kg >= 1000 ? $n($kg / 1000, 1) . ' t CO₂e' : $n($kg) . ' kg CO₂e';
        $pct = static fn (?float $r): string => $r === null ? '—' : $n(abs($r) * 100, 1) . '%';
        $month = $s['period']['month'];
        $company = $s['company']['name'];
        $verified = $s['impact']['verified'];

        $summary = $en
            ? "In {$month}, {$company} used {$n($s['energy']['kwh'])} kWh of electricity, costing {$eur($s['energy']['eur'])} in energy charges (excl. VAT) and emitting {$co2($s['carbon']['scope2_location_kg'])} (Scope 2, location-based). EnergyFlow found {$n($s['waste']['kwh'])} kWh of waste ({$pct($s['waste']['share'])} of consumption), worth {$eur($s['waste']['eur'])}."
            : "Në {$month}, {$company} konsumoi {$n($s['energy']['kwh'])} kWh energji elektrike, me kosto energjie {$eur($s['energy']['eur'])} (pa TVSH) dhe emetime {$co2($s['carbon']['scope2_location_kg'])} (Scope 2, sipas vendndodhjes). EnergyFlow gjeti {$n($s['waste']['kwh'])} kWh humbje ({$pct($s['waste']['share'])} e konsumit), me vlerë {$eur($s['waste']['eur'])}.";
        if ($verified['kwh'] > 0) {
            $summary .= $en
                ? " Verified savings from the actions taken so far amount to {$n($verified['kwh'])} kWh, {$eur($verified['eur'])} and {$co2($verified['co2_kg'])}."
                : " Kursimet e verifikuara nga veprimet e ndërmarra deri tani arrijnë {$n($verified['kwh'])} kWh, {$eur($verified['eur'])} dhe {$co2($verified['co2_kg'])}.";
        }

        $change = $s['previous']['change_ratio'];
        $basis = self::BASIS[$language][$s['previous']['basis'] ?? 'previous_month'];
        $changes = $change === null ? '' : ($en
            ? "Compared with {$basis}, consumption " . ($change >= 0 ? 'rose' : 'fell') . " by {$pct($change)}."
            : "Krahasuar me {$basis}, konsumi " . ($change >= 0 ? 'u rrit' : 'ra') . " me {$pct($change)}.");
        $largest = $s['waste']['largest'];
        if ($largest !== null) {
            $type = self::wasteType($largest['type'], $language);
            $changes .= $en
                ? " The largest waste event was {$largest['name']} ({$type}): {$n($largest['kwh'])} kWh and {$eur($largest['eur'])}."
                : " Ngjarja më e madhe e humbjes ishte {$largest['name']} ({$type}): {$n($largest['kwh'])} kWh dhe {$eur($largest['eur'])}.";
        }
        $offs = $s['actions']['turn_offs_manual'] + $s['actions']['turn_offs_automatic'];
        if ($offs > 0) {
            $changes .= $en
                ? " EnergyFlow switched machines off {$offs} times, each verified by the meter ({$s['actions']['turn_offs_automatic']} by automations)."
                : " EnergyFlow fiku makineri {$offs} herë, secila e verifikuar nga matësi ({$s['actions']['turn_offs_automatic']} nga automatizimet).";
        }

        $recs = $s['recommendations'];
        if ($recs === []) {
            $outlook = $en ? 'There are no open opportunities at the moment; EnergyFlow keeps checking every hour.' : 'Për momentin nuk ka mundësi të hapura; EnergyFlow vazhdon të kontrollojë çdo orë.';
        } else {
            $top = $recs[0];
            $total = array_sum(array_column($recs, 'eur_month'));
            $outlook = $en
                ? 'The most valuable next step is to ' . self::action($top, 'en') . ", worth about {$eur($top['eur_month'])} per month. Together, the " . count($recs) . " open opportunities could save {$eur($total)} per month."
                : 'Hapi më i vlefshëm është që ' . self::action($top, 'sq') . ", rreth {$eur($top['eur_month'])} në muaj. Së bashku, " . count($recs) . " mundësitë e hapura mund të kursejnë {$eur($total)} në muaj.";
            foreach ($recs as $r) {
                if ($r['impact_tag'] === 'eur') {
                    $outlook .= $en
                        ? ' Running ' . $r['machine'] . ' in the night tariff saves money but not CO₂e, because Kosovo\'s grid factor is an annual average.'
                        : ' Puna me ' . $r['machine'] . ' në tarifën e natës kursen para, por jo CO₂e, sepse faktori i rrjetit të Kosovës është mesatare vjetore.';
                    break;
                }
            }
        }
        return ['summary' => $summary, 'changes' => trim($changes), 'outlook' => $outlook];
    }

    private static function action(array $r, string $language): string
    {
        $code = $r['machine'] ?? '';
        $en = [
            'after_hours_schedule' => "switch {$code} off automatically after the shift",
            'compressed_air_leak' => "find and fix the compressed-air leaks on {$code}",
            'efficiency_drift' => "service {$code}, which needs more power than its reference",
            'tou_shift' => "run {$code} in the night tariff",
        ];
        $sq = [
            'after_hours_schedule' => "të fiket automatikisht {$code} pas ndërrimit",
            'compressed_air_leak' => "të gjenden dhe riparohen rrjedhjet e ajrit në {$code}",
            'efficiency_drift' => "të servisohet {$code}, që kërkon më shumë fuqi se referenca",
            'tou_shift' => "të punohet me {$code} në tarifën e natës",
        ];
        return ($language === 'sq' ? $sq : $en)[$r['title_key']] ?? $code;
    }

    private static function wasteType(string $type, string $language): string
    {
        $labels = [
            'en' => ['after_hours' => 'running after hours', 'idle' => 'idling during production', 'excess_vs_baseline' => 'efficiency drift'],
            'sq' => ['after_hours' => 'punë pas orarit', 'idle' => 'në pritje gjatë prodhimit', 'excess_vs_baseline' => 'rënie e efikasitetit'],
        ];
        return $labels[$language][$type] ?? $type;
    }

}
