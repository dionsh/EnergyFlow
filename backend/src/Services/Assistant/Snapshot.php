<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Assistant;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\LiveService;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Analytics\Period;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\CarbonReport;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\WasteReport;
use EnergyFlow\Services\Impact\ImpactService;
use EnergyFlow\Services\Optimization\Recommendations;
use EnergyFlow\Services\Scan\ScanService;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * The company's energy picture, compact and rounded, for questions the
 * deterministic answers don't cover ("why was the bill higher?"). Built from the
 * same services as the pages. The model answers only from this and the
 * knowledge base; the grounding check compares every number it writes with it.
 *
 * Only the sections a question is about are loaded: it keeps each request well
 * inside Groq's free-tier tokens-per-minute, and makes building it faster.
 * Aggregates only: no personal data, no device secrets.
 */
final class Snapshot
{
    /** Section → where it comes from in the app, for the answer's sources. */
    public const SECTIONS = [
        'live' => ['Live readings', 'Leximet live', '/live'],
        'today' => ['Today', 'Sot', '/'],
        'month_to_date' => ['This month so far', 'Ky muaj deri tani', '/'],
        'month_end_projection' => ['Month-end projection', 'Parashikimi për fund të muajit', '/'],
        'monthly_history' => ['Monthly history', 'Historiku mujor', '/reports'],
        'machines_this_month' => ['Energy by machine', 'Energjia sipas makinerisë', '/machines'],
        'waste_this_month' => ['Waste', 'Humbjet', '/waste'],
        'waste_last_7_days' => ['Waste · last 7 days', 'Humbjet · 7 ditët e fundit', '/waste?period=7d'],
        'open_alerts' => ['Alerts', 'Alarmet', '/waste?tab=alerts'],
        'open_recommendations' => ['Opportunities', 'Mundësitë', '/opportunities'],
        'verified_savings' => ['Impact', 'Ndikimi', '/impact'],
        'carbon_this_month' => ['Carbon & ESG', 'Karboni & ESG', '/carbon'],
        'automations' => ['Automations', 'Automatizimet', '/automations'],
        'tariff' => ['Tariff', 'Tarifa', '/settings'],
        'saved_bills' => ['Saved bills', 'Faturat e ruajtura', '/scan'],
        'context' => ['This page', 'Kjo faqe', null],
        'knowledge' => ['EnergyFlow methodology and sourced facts', 'Metodologjia e EnergyFlow dhe fakte me burim', null],
    ];

    /** Words that make a section relevant (English and Albanian, lower-case). */
    private const TOPICS = [
        'live' => ['now', 'right now', 'currently', 'running', 'still on', 'tonight', 'turn off', 'switch off', 'tani', 'sonte', 'ndezur', 'punon', 'fik', 'ende'],
        'today' => ['today', 'sot'],
        'month_end_projection' => ['forecast', 'projection', 'end of the month', 'end of month', 'expect', 'bill', 'parashik', 'fund të muajit', 'fatur'],
        'monthly_history' => ['last month', 'previous month', 'higher', 'lower', 'increase', 'decrease', 'rose', 'fell', 'compare', 'more than', 'less than', 'trend',
            'bill', 'muajin e kaluar', 'muaji i kaluar', 'më e lartë', 'më i lartë', 'më e ulët', 'rrit', 'rënie', 'krahas', 'fatur',
            'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
            'janar', 'shkurt', 'prill', 'qershor', 'korrik', 'gusht', 'shtator', 'tetor', 'nëntor', 'dhjetor'],
        'machines_this_month' => ['machine', 'makiner', 'consum', 'konsum', ' use', 'using', 'used', 'kwh', 'biggest', 'most', 'which', 'cila', 'cilat', 'harxh',
            'compressor', 'kompresor', 'moulder', 'chiller', 'pump', 'pomp', 'light', 'drit', 'hvac', 'office', 'zyr', 'more', 'më shumë', 'higher', 'rrit'],
        'waste_this_month' => ['waste', 'humb', 'after hours', 'pas orarit', 'idle', 'pritje', 'drift', 'leak', 'rrjedh', 'loss'],
        'waste_last_7_days' => ['week', 'javë', 'jave', '7 days', '7 ditë'],
        'open_alerts' => ['alert', 'alarm', 'drift', 'spike', 'overload', 'kulm', 'mbingarkes', 'problem', 'offline', 'failed', 'issue', 'wrong', 'gabim', 'paralajm', 'more power', 'më shumë fuqi'],
        'open_recommendations' => ['save', 'saving', 'reduce', 'recommend', 'should', 'change', 'improve', 'opportunit', 'fix', 'stop', 'kursim', 'kursej',
            'rekomand', 'ndrysho', 'përmirëso', 'mundësi', 'what if', 'ndal', 'riparo', 'service', 'servis'],
        'verified_savings' => ['saved', 'savings', 'impact', 'verified', 'before', 'after', 'kursyer', 'kursime', 'ndikim', 'verifik', 'auto-off', 'automation'],
        'carbon_this_month' => ['co2', 'co₂', 'carbon', 'emission', 'esg', 'scope', 'vsme', 'karbon', 'emetim', 'climate', 'klim', 'ghg'],
        'automations' => ['automation', 'automat', 'policy', 'policies', 'auto-off', 'polic', 'schedule', 'orar'],
        'tariff' => ['tariff', 'rate', 'price', 'night', 'peak', 'tarif', 'çmim', 'natë', 'nate', 'bill', 'fatur', 'cost', 'kosto', 'vat', 'tvsh'],
        'saved_bills' => ['bill', 'fatur', 'invoice', 'supplier', 'furnizues', 'kesco', 'fake', 'falsifik', 'correct', 'saktë', 'sakte'],
    ];

    /** The sections a question needs: by topic words, by the machines it names, and by page context. */
    public static function sectionsFor(int $companyId, string $question, array $context): array
    {
        $q = ' ' . mb_strtolower($question) . ' ';
        $wanted = [];
        foreach (self::TOPICS as $section => $words) {
            foreach ($words as $word) {
                if (str_contains($q, $word)) {
                    $wanted[$section] = true;
                    break;
                }
            }
        }
        if (isset($context['machine_id']) || MachineMatcher::find($companyId, $question) !== []) {
            $wanted += ['machines_this_month' => true, 'waste_this_month' => true, 'open_alerts' => true, 'open_recommendations' => true, 'live' => true];
        }
        if (isset($context['waste_event_id'])) {
            $wanted += ['waste_this_month' => true, 'open_recommendations' => true];
        }
        if (isset($context['recommendation_id'])) {
            $wanted += ['open_recommendations' => true, 'verified_savings' => true];
        }
        if ($wanted === []) {
            $wanted = ['machines_this_month' => true, 'waste_this_month' => true, 'open_recommendations' => true, 'open_alerts' => true];
        }
        return array_keys(array_intersect_key(self::SECTIONS, $wanted)); // stable order
    }

    public static function build(int $companyId, array $context = [], string $question = ''): array
    {
        $now = Clock::now($companyId);
        $time = LocalTime::forCompany($companyId);
        $tariff = TariffBook::forCompany($companyId);
        $local = static fn (?string $iso): ?string => $iso === null ? null : $time->format((int) strtotime($iso), 'Y-m-d H:i');
        $company = Database::one('SELECT name, city, employees, is_demo FROM companies WHERE id = ?', [$companyId]);
        $overview = OverviewService::summary($companyId);
        $has = (bool) ($overview['has_data'] ?? false);
        $mtd = Period::parse('mtd', $now, $time);
        $incomer = OverviewService::incomerId($companyId);
        $answers = new DataAnswers($companyId, new Say('en'), ['role' => 'viewer']);

        // A question about particular machines (named, or the machine page) only gets
        // those machines' alerts and opportunities, so the model can't mix them up.
        $focus = array_column(MachineMatcher::find($companyId, $question), 'code');
        if (isset($context['machine_id'])) {
            $focus[] = (string) Database::value('SELECT code FROM machines WHERE company_id = ? AND id = ?', [$companyId, (int) $context['machine_id']]);
        }
        $focus = array_values(array_unique(array_filter($focus)));
        $about = static fn (?string $code): bool => $focus === [] || in_array($code, $focus, true);

        $facts = [
            'company' => ['name' => $company['name'], 'city' => $company['city'], 'employees' => $company['employees'] === null ? null : (int) $company['employees'],
                'demo_with_simulated_machines' => (bool) $company['is_demo']],
            'now_local' => $time->format($now, 'Y-m-d H:i') . ' (' . $time->format($now, 'l') . ')',
            'month_to_date' => $has ? self::monthToDate($companyId, $overview, $mtd, $tariff, $incomer, $answers->periodLabel($mtd), $time, $now) : null,
            'notes' => 'energy_charges are energy (kWh) charges only; a bill also has fixed and demand charges and VAT. Sections not present were not loaded for this question.'
                . ($focus === [] ? '' : ' The question is about ' . implode(', ', $focus) . ': open_alerts and open_recommendations list only those machines.'),
        ];
        foreach (self::sectionsFor($companyId, $question, $context) as $section) {
            $facts[$section] = match ($section) {
                'live' => self::live($companyId, $local),
                'today' => $overview['today'] ?? null,
                'month_end_projection' => $has ? [
                    'kwh' => $overview['projection']['kwh'], 'co2_kg' => $overview['projection']['co2_kg'],
                    'bill_excl_vat' => $overview['projection']['bill']['subtotal'] ?? null, 'bill_incl_vat' => $overview['projection']['bill']['total'] ?? null,
                    'method' => 'month to date + average of the same kind of day over the last 28 days',
                    'likely_range_p10_p90' => $overview['projection']['range'] ?? null,
                    'backtest_typical_error' => $overview['projection']['backtest']['wape'] ?? null,
                ] : null,
                'monthly_history' => self::months($companyId, $now, $time, $tariff, $incomer, (float) ($overview['emission_factor']['value'] ?? 0.0)),
                'machines_this_month' => self::machines($companyId, $mtd, $tariff, $incomer),
                'waste_this_month' => self::waste($companyId, $mtd, 5, true),
                'waste_last_7_days' => self::waste($companyId, Period::parse('7d', $now, $time), 3, false),
                'open_alerts' => array_values(array_filter(self::alerts($companyId, $local), static fn (array $a): bool => $about($a['machine'] ?? null))),
                'open_recommendations' => array_map(static fn (array $r): array => [
                    'title' => $answers->recTitle($r), 'machine' => $r['machine']['code'] ?? null,
                    'eur_per_month' => round($r['per_month']['eur'], 2), 'kwh_per_month' => round($r['per_month']['kwh'], 0), 'co2_kg_per_month' => round($r['per_month']['co2_kg'], 0),
                    'saves' => $r['impact_tag'] === 'eur' ? 'money only (no CO2 change)' : 'money and CO2', 'effort' => $r['effort'], 'confidence' => $r['confidence'],
                ], array_slice(array_values(array_filter(Recommendations::list($companyId, 'open'), static fn (array $r): bool => $about($r['machine']['code'] ?? null))), 0, 5)),
                'verified_savings' => self::impact($companyId, $local),
                'carbon_this_month' => self::carbon($companyId, $mtd),
                'automations' => array_map(static fn (array $p): array => [
                    'machine' => $p['code'], 'type' => $p['type'], 'params' => json_decode((string) $p['params'], true), 'mode' => $p['mode'], 'active' => (bool) $p['is_active'],
                ], Database::all('SELECT p.type, p.params, p.mode, p.is_active, m.code FROM automation_policies p JOIN machines m ON m.id = p.machine_id WHERE p.company_id = ?', [$companyId])),
                'saved_bills' => array_map(static fn (array $b): array => [
                    'month' => $b['month'], 'supplier' => $b['supplier'], 'billed_kwh' => $b['kwh'], 'measured_kwh' => $b['metered_kwh'], 'total_eur' => $b['total_eur'],
                    'check_verdict' => $b['verdict'], 'failed_checks' => array_values(array_column(array_filter($b['checks'], static fn (array $c): bool => $c['status'] === 'fail'), 'key')),
                ], array_slice(ScanService::bills($companyId), 0, 3)),
                'tariff' => $tariff === null ? null : [
                    'name' => $tariff->summary()['name'], 'rate_high_eur_kwh' => $tariff->summary()['rate_high'], 'rate_low_eur_kwh' => $tariff->summary()['rate_low'],
                    'vat_rate' => $tariff->summary()['vat_rate'], 'period_now' => $tariff->period($now),
                ],
                default => null,
            };
        }
        $page = self::context($companyId, $context, $local);
        if ($page !== null) {
            $facts['context'] = $page;
        }
        return $facts;
    }

    private static function monthToDate(int $companyId, array $overview, Period $mtd, ?TariffBook $tariff, ?int $incomer, string $label, LocalTime $time, int $now): array
    {
        $site = EnergyQuery::site(EnergyQuery::byMachine($companyId, $mtd->from, $mtd->to, null, $tariff), $incomer);
        $month = $overview['month'];
        return [
            'period' => $label . ' (' . $time->date($mtd->from) . ' – ' . $time->format($now, 'Y-m-d H:i') . ')',
            'kwh' => $month['kwh'], 'energy_charges_eur_excl_vat' => round(EnergyQuery::energyCost($site, $tariff), 2), 'co2_kg' => $month['co2_kg'],
            'kwh_high_tariff' => $month['kwh_high'], 'kwh_low_tariff' => $month['kwh_low'], 'peak_kw' => $month['peak_kw'],
            'previous_month_same_days_kwh' => $month['previous_same_period_kwh'], 'change_ratio' => $month['change_ratio'],
            'bill_so_far' => $month['bill_so_far'] === null ? null : ['excl_vat' => $month['bill_so_far']['subtotal'], 'incl_vat' => $month['bill_so_far']['total']],
        ];
    }

    private static function live(int $companyId, callable $local): array
    {
        $live = LiveService::snapshot($companyId);
        return [
            'site_kw' => $live['site_kw'],
            'machines' => array_map(static fn (array $m): array => array_filter([
                'code' => $m['code'], 'state' => $m['state'], 'kw' => $m['power_kw'], 'scheduled_now' => $m['scheduled_now'], 'today_kwh' => $m['today_kwh'],
                'can_turn_off' => $m['control']['can_turn_off'], 'cannot_turn_off_because' => $m['control']['reason'],
                'after_hours' => $m['after_hours'] === null ? null : [
                    'since' => $local($m['after_hours']['since']), 'minutes' => intdiv($m['after_hours']['duration_s'], 60),
                    'kwh' => $m['after_hours']['kwh'], 'eur' => $m['after_hours']['eur'], 'co2_kg' => $m['after_hours']['co2_kg'],
                    'projected_eur_if_left_on_until_next_shift' => $m['after_hours']['projection']['eur'] ?? null,
                ],
            ], static fn (mixed $v): bool => $v !== null), $live['machines']),
        ];
    }

    /** Month to date per machine, with the same days of last month. */
    private static function machines(int $companyId, Period $mtd, ?TariffBook $tariff, ?int $incomer): array
    {
        $by = EnergyQuery::byMachine($companyId, $mtd->from, $mtd->to, null, $tariff);
        $prev = EnergyQuery::byMachine($companyId, $mtd->previousFrom, $mtd->previousTo, null, $tariff);
        $site = EnergyQuery::site($by, $incomer);
        $machines = [];
        foreach (Database::all(
            "SELECT m.id, m.code, m.name, m.type_code, m.criticality, s.name AS schedule FROM machines m LEFT JOIN schedules s ON s.id = m.schedule_id
              WHERE m.company_id = ? AND m.kind = 'machine' AND m.archived_at IS NULL ORDER BY m.code",
            [$companyId],
        ) as $m) {
            $kwh = $by[(int) $m['id']]['kwh'] ?? 0.0;
            $before = $prev[(int) $m['id']]['kwh'] ?? 0.0;
            $machines[] = [
                'code' => $m['code'], 'name' => $m['name'], 'type' => $m['type_code'], 'schedule' => $m['schedule'], 'criticality' => $m['criticality'],
                'kwh_month_to_date' => round($kwh, 1),
                'eur_month_to_date' => round(isset($by[(int) $m['id']]) ? EnergyQuery::energyCost($by[(int) $m['id']], $tariff) : 0.0, 2),
                'share_of_site' => $site['kwh'] > 0 ? round($kwh / $site['kwh'], 3) : null,
                'previous_month_same_days_kwh' => round($before, 1),
                'change_ratio' => $before > 0 ? round($kwh / $before - 1, 3) : null,
            ];
        }
        usort($machines, static fn (array $a, array $b): int => $b['kwh_month_to_date'] <=> $a['kwh_month_to_date']);
        return $machines;
    }

    private static function waste(int $companyId, Period $period, int $machines, bool $byType): array
    {
        $w = WasteReport::summary($companyId, $period);
        $out = [
            'kwh' => $w['totals']['kwh'], 'eur' => $w['totals']['eur'], 'co2_kg' => $w['totals']['co2_kg'], 'events' => $w['totals']['events'],
            'share_of_consumption' => $w['totals']['share_of_consumption'],
            'by_machine' => array_map(static fn (array $r): array => ['code' => $r['machine']['code'], 'kwh' => $r['kwh'], 'eur' => $r['eur'], 'events' => $r['events']], array_slice($w['by_machine'], 0, $machines)),
        ];
        if ($byType) {
            $out['by_type'] = array_map(static fn (array $r): array => ['type' => $r['type'], 'kwh' => $r['kwh'], 'eur' => $r['eur'], 'events' => $r['events']], $w['by_type']);
        }
        return $out;
    }

    private static function impact(int $companyId, callable $local): array
    {
        $impact = ImpactService::summary($companyId);
        return [
            'total' => $impact['verified'],
            'interventions' => array_map(static fn (array $i): array => [
                'machine' => $i['machine']['code'], 'action' => $i['kind'] === 'policy' ? 'auto-off ' . ($i['policy_params']['grace_min'] ?? '?') . ' min after schedule' : $i['label'],
                'since' => $local($i['since']), 'reporting_days' => $i['reporting']['days'],
                'savings_kwh' => $i['savings']['kwh'], 'ci90_kwh' => $i['savings']['ci90_kwh'], 'eur' => $i['savings']['eur'], 'co2_kg' => $i['savings']['co2_kg'],
                'status' => $i['verified'] ? 'verified' : ($i['collecting'] ? 'collecting data' : 'not yet significant'),
            ], $impact['interventions']),
        ];
    }

    private static function carbon(int $companyId, Period $mtd): array
    {
        $c = CarbonReport::summary($companyId, $mtd);
        return [
            'scope2_location_kg' => $c['scope2_location_kg'], 'previous_same_days_kg' => $c['previous']['scope2_location_kg'], 'scope1' => $c['scope1']['status'],
            'scope2_market' => 'not available (no residual mix for Kosovo)', 'avoided_by_verified_actions_kg' => $c['avoided_verified_kg'],
            'factor_kg_per_kwh' => $c['factor']['value'], 'factor_source' => $c['factor']['source_name'] . ', ' . $c['factor']['methodology'],
        ];
    }

    /** The last three complete months, site and per machine, for "why was it higher than in…" questions. */
    private static function months(int $companyId, int $now, LocalTime $time, ?TariffBook $tariff, ?int $incomer, float $factor): array
    {
        $codes = array_column(Database::all("SELECT id, code FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL", [$companyId]), 'code', 'id');
        $months = [];
        $start = $time->startOfMonth($now);
        for ($i = 0; $i < 3; $i++) {
            $start = $time->startOfMonth($start - 86400);
            $period = Period::parse('month:' . $time->format($start, 'Y-m'), $now, $time);
            $by = EnergyQuery::byMachine($companyId, $period->from, $period->to, null, $tariff);
            $site = EnergyQuery::site($by, $incomer);
            if ($site['kwh'] <= 0) {
                break;
            }
            $waste = WasteReport::summary($companyId, $period)['totals'];
            $perMachine = [];
            foreach ($codes as $id => $code) {
                $perMachine[$code] = round($by[$id]['kwh'] ?? 0.0, 0);
            }
            arsort($perMachine);
            $peak = EnergyQuery::peakKw($companyId, $period->from, $period->to, $incomer);
            $bill = $tariff?->bill($site['kwh_high'], $site['kwh_low'], $peak, $site['kvarh']);
            $months[] = [
                'month' => $time->format($start, 'Y-m'), 'kwh' => round($site['kwh'], 0), 'kwh_high_tariff' => round($site['kwh_high'], 0),
                'energy_charges_eur_excl_vat' => round(EnergyQuery::energyCost($site, $tariff), 2),
                'estimated_bill_eur_excl_vat' => $bill['subtotal'] ?? null, 'estimated_bill_eur_incl_vat' => $bill['total'] ?? null,
                'co2_kg' => round($site['kwh'] * $factor, 0), 'peak_kw' => round($peak, 1),
                'waste_kwh' => $waste['kwh'], 'waste_eur' => $waste['eur'], 'kwh_by_machine' => $perMachine,
            ];
        }
        return $months;
    }

    /** What the user is looking at ("Explain this"): the machine, waste event or opportunity of the page. */
    private static function context(int $companyId, array $context, callable $local): ?array
    {
        $out = [];
        if (isset($context['page'])) {
            $out['page'] = (string) $context['page'];
        }
        try {
            if (isset($context['waste_event_id'])) {
                $e = WasteReport::show($companyId, (int) $context['waste_event_id']);
                $evidence = array_filter($e['evidence'], static fn (mixed $v): bool => is_scalar($v));
                $out['waste_event'] = [
                    'type' => $e['type'], 'machine' => $e['machine']['code'] . ' ' . $e['machine']['name'], 'severity' => $e['severity'],
                    'started' => $local($e['started_at']), 'ended' => $local($e['ended_at']), 'ongoing' => $e['ongoing'], 'minutes' => intdiv($e['duration_s'], 60),
                    'kwh' => $e['kwh'], 'eur' => $e['eur'], 'co2_kg' => $e['co2_kg'], 'signals' => $e['signals'], 'deviation_pct' => $e['deviation_pct'],
                    'status' => $e['action_status'], 'evidence' => $evidence,
                ];
            }
            if (isset($context['recommendation_id'])) {
                $r = Recommendations::show($companyId, (int) $context['recommendation_id']);
                $out['opportunity'] = [
                    'title' => (new DataAnswers($companyId, new Say('en'), ['role' => 'viewer']))->recTitle($r), 'machine' => $r['machine']['code'] ?? null,
                    'status' => $r['status'], 'eur_per_month' => $r['per_month']['eur'], 'kwh_per_month' => $r['per_month']['kwh'], 'co2_kg_per_month' => $r['per_month']['co2_kg'],
                    'effort' => $r['effort'], 'confidence' => $r['confidence'], 'saves' => $r['impact_tag'] === 'eur' ? 'money only' : 'money and CO2',
                    'what_if' => is_array($r['what_if']) ? array_filter($r['what_if'], static fn (mixed $v): bool => is_scalar($v) || (is_array($v) && count($v) <= 6)) : null,
                ];
            }
            if (isset($context['machine_id'])) {
                $m = Database::one(
                    "SELECT m.id, m.code, m.name, m.type_code, m.rated_power_kw, m.criticality, m.control_mode, s.name AS schedule
                       FROM machines m LEFT JOIN schedules s ON s.id = m.schedule_id WHERE m.company_id = ? AND m.id = ?",
                    [$companyId, (int) $context['machine_id']],
                );
                if ($m !== null) {
                    $out['machine'] = [
                        'code' => $m['code'], 'name' => $m['name'], 'type' => $m['type_code'], 'rated_kw' => $m['rated_power_kw'] === null ? null : (float) $m['rated_power_kw'],
                        'criticality' => $m['criticality'], 'control' => $m['control_mode'], 'schedule' => $m['schedule'],
                        'waste_events_this_month' => array_map(static fn (array $w): array => ['type' => $w['type'], 'started' => $local($w['started_at']), 'kwh' => $w['kwh'], 'eur' => $w['eur']],
                            array_slice(WasteReport::list($companyId, Period::parse('mtd', Clock::now($companyId), LocalTime::forCompany($companyId)), ['machine_id' => (int) $m['id']]), 0, 8)),
                    ];
                }
            }
        } catch (\Throwable) {
            // A stale id (deleted event, other company) simply adds no context.
        }
        return $out === [] ? null : $out;
    }

    private static function alerts(int $companyId, callable $local): array
    {
        $rows = Database::all(
            "SELECT a.severity, a.type, a.params, a.opened_at, m.code FROM alerts a LEFT JOIN machines m ON m.id = a.machine_id
              WHERE a.company_id = ? AND a.status <> 'resolved' ORDER BY FIELD(a.severity, 'critical', 'warning', 'info'), a.opened_at DESC LIMIT 8",
            [$companyId],
        );
        return array_map(static function (array $a) use ($local): array {
            $p = json_decode((string) $a['params'], true) ?: [];
            return array_filter([
                'severity' => $a['severity'], 'type' => strtolower($a['type']), 'machine' => $a['code'] ?? ($p['serial'] ?? null),
                'opened' => $local(Time::iso($a['opened_at'])), 'kwh' => $p['kwh'] ?? null, 'eur' => $p['eur'] ?? null,
                'deviation_pct' => $p['deviation_pct'] ?? null, 'leak_signature' => ($p['leak'] ?? false) ?: null,
            ], static fn (mixed $v): bool => $v !== null);
        }, $rows);
    }
}
