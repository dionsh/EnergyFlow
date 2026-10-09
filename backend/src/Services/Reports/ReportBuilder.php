<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Reports;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\Analytics\Period;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Carbon\CarbonReport;
use EnergyFlow\Services\Carbon\EmissionFactors;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Detection\WasteReport;
use EnergyFlow\Services\Impact\ImpactService;
use EnergyFlow\Services\Optimization\Recommendations;
use EnergyFlow\Services\RateLimiter;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Period sustainability report (docs/06-ux-design.md §3.9). The snapshot freezes
 * every figure, the factor and the tariff it used, so a finalised report never
 * changes when data or settings change later. The narrative is written over the
 * frozen snapshot (Groq when configured, checked against the numbers; a
 * deterministic template otherwise) and can be edited before finalising.
 */
final class ReportBuilder
{
    public static function create(int $companyId, int $userId, string $type, string $periodKey, string $language): array
    {
        RateLimiter::hit('reports:' . $companyId, 10, 3600);
        $now = Clock::now($companyId);
        $time = LocalTime::forCompany($companyId);
        $period = self::resolvePeriod($type, $periodKey, $now, $time);
        $label = match ($type) {
            'monthly' => $periodKey,
            'daily' => $time->date($period->from),
            'weekly' => $time->date($period->from) . ' – ' . $time->date($period->to - 1),
        };
        $snapshot = self::snapshot($companyId, $period, $label, $now, $time);
        $narrative = NarrativeWriter::write($snapshot, $language);

        $id = Database::insert(
            "INSERT INTO reports (company_id, type, title, period_start, period_end, status, language, snapshot, narrative, narrative_source, version, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, 1, ?, FROM_UNIXTIME(?))",
            [$companyId, $type . '_sustainability', $snapshot['company']['name'] . ' · ' . $label, gmdate('Y-m-d', $period->from + 43200), gmdate('Y-m-d', $period->to - 1),
             $language, json_encode($snapshot, JSON_UNESCAPED_UNICODE), json_encode($narrative['sections'], JSON_UNESCAPED_UNICODE),
             $narrative['source'], $userId, $now],
        );
        AuditLog::record($companyId, $userId, 'report.create', 'report', $id, ['type' => $type, 'period' => $label, 'language' => $language, 'narrative' => $narrative['source']]);
        return self::find($companyId, $id);
    }

    private static function resolvePeriod(string $type, string $key, int $now, LocalTime $time): Period
    {
        if ($type === 'monthly') {
            return Period::parse('month:' . $key, $now, $time);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $key, $time->zone());
        if ($date === false || $date->format('Y-m-d') !== $key) {
            throw HttpException::validation(['period' => 'invalid_date']);
        }
        if ($type === 'daily') {
            $from = $time->at($key);
            $to = min($now, $time->at($date->modify('+1 day')->format('Y-m-d')));
        } else {
            $monday = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
            $from = $time->at($monday->format('Y-m-d'));
            $to = min($now, $time->at($monday->modify('+7 days')->format('Y-m-d')));
        }
        if ($from >= $now) {
            throw HttpException::badRequest('invalid_period', 'That period has not started yet.');
        }
        return Period::between($from, $to, $type . ':' . $key);
    }

    public static function list(int $companyId): array
    {
        return array_map(static fn (array $r): array => self::present($r, false), Database::all(
            'SELECT r.*, u.full_name AS created_by_name, f.full_name AS finalized_by_name FROM reports r
               LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users f ON f.id = r.finalized_by
              WHERE r.company_id = ? ORDER BY r.period_start DESC, r.id DESC',
            [$companyId],
        ));
    }

    public static function find(int $companyId, int $id): array
    {
        $row = Database::one(
            'SELECT r.*, u.full_name AS created_by_name, f.full_name AS finalized_by_name FROM reports r
               LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users f ON f.id = r.finalized_by
              WHERE r.company_id = ? AND r.id = ?',
            [$companyId, $id],
        ) ?? throw HttpException::notFound('report_not_found', 'Report not found.');
        return self::present($row, true);
    }

    /** @param array<string, string> $sections */
    public static function updateNarrative(int $companyId, int $userId, int $id, array $sections): array
    {
        $row = Database::one('SELECT status, narrative FROM reports WHERE company_id = ? AND id = ?', [$companyId, $id])
            ?? throw HttpException::notFound('report_not_found', 'Report not found.');
        if ($row['status'] === 'final') {
            throw HttpException::conflict('report_final', 'A finalised report cannot be changed.');
        }
        $narrative = (json_decode((string) $row['narrative'], true) ?: []);
        foreach (['summary', 'changes', 'outlook'] as $key) {
            if (isset($sections[$key])) {
                $narrative[$key] = mb_substr(trim((string) $sections[$key]), 0, 2000);
            }
        }
        Database::run("UPDATE reports SET narrative = ?, narrative_source = 'edited' WHERE id = ?", [json_encode($narrative, JSON_UNESCAPED_UNICODE), $id]);
        AuditLog::record($companyId, $userId, 'report.narrative', 'report', $id);
        return self::find($companyId, $id);
    }

    public static function finalize(int $companyId, int $userId, int $id): array
    {
        $updated = Database::run(
            "UPDATE reports SET status = 'final', finalized_by = ?, finalized_at = FROM_UNIXTIME(?) WHERE company_id = ? AND id = ? AND status = 'draft'",
            [$userId, Clock::now($companyId), $companyId, $id],
        );
        if ($updated === 0) {
            self::find($companyId, $id); // 404 when missing
            throw HttpException::conflict('report_final', 'This report is already final.');
        }
        AuditLog::record($companyId, $userId, 'report.finalize', 'report', $id);
        return self::find($companyId, $id);
    }

    /** Every figure the report shows, frozen. */
    public static function snapshot(int $companyId, Period $period, string $month, int $now, LocalTime $time): array
    {
        $company = Database::one('SELECT name, legal_form, business_number, nace_code, employees, annual_turnover_eur, city, country, is_demo FROM companies WHERE id = ?', [$companyId]);
        $tariff = TariffBook::forCompany($companyId);
        $factor = EmissionFactors::details($companyId);
        $incomerId = OverviewService::incomerId($companyId);
        $byMachine = EnergyQuery::byMachine($companyId, $period->from, $period->to, null, $tariff);
        $site = EnergyQuery::site($byMachine, $incomerId);
        $previous = EnergyQuery::site(EnergyQuery::byMachine($companyId, $period->previousFrom, $period->previousTo, null, $tariff), $incomerId);
        $peak = EnergyQuery::peakKw($companyId, $period->from, $period->to, $incomerId);
        $days = ($period->to - $period->from) / 86400;
        $monthDays = (int) $time->format($period->from + 43200, 't');
        $bill = $tariff?->bill($site['kwh_high'], $site['kwh_low'], $peak, $site['kvarh'], min(1.0, $days / $monthDays));

        $machines = [];
        foreach (Database::all("SELECT id, code, name, type_code FROM machines WHERE company_id = ? AND kind = 'machine' AND archived_at IS NULL", [$companyId]) as $m) {
            $e = $byMachine[(int) $m['id']] ?? ['kwh' => 0.0, 'kwh_high' => 0.0, 'kwh_low' => 0.0];
            $machines[] = [
                'code' => $m['code'], 'name' => $m['name'], 'type' => $m['type_code'],
                'kwh' => round($e['kwh'], 1), 'eur' => round(EnergyQuery::energyCost($e, $tariff), 2), 'co2_kg' => round($e['kwh'] * $factor['value'], 1),
                'share' => $site['kwh'] > 0 ? round($e['kwh'] / $site['kwh'], 4) : 0.0,
            ];
        }
        usort($machines, static fn (array $a, array $b): int => $b['kwh'] <=> $a['kwh']);

        $waste = WasteReport::summary($companyId, $period);
        $largest = Database::one(
            "SELECT w.type, w.energy_kwh, w.cost_eur, w.co2_kg, w.started_at, m.code, m.name FROM waste_events w JOIN machines m ON m.id = w.machine_id
              WHERE w.company_id = ? AND w.started_at >= FROM_UNIXTIME(?) AND w.started_at < FROM_UNIXTIME(?) AND w.action_status <> 'dismissed'
              ORDER BY w.cost_eur DESC LIMIT 1",
            [$companyId, $period->from, $period->to],
        );
        $commands = Database::one(
            "SELECT SUM(status = 'verified' AND source = 'user') AS manual, SUM(status = 'verified' AND source = 'policy') AS automatic, SUM(status = 'failed') AS failed
               FROM device_commands WHERE company_id = ? AND requested_at >= FROM_UNIXTIME(?) AND requested_at < FROM_UNIXTIME(?)",
            [$companyId, $period->from, $period->to],
        );
        $impact = ImpactService::summary($companyId);
        $year = (int) $time->format($period->from + 43200, 'Y');
        $carbon = CarbonReport::summary($companyId, $period);
        $vsme = CarbonReport::vsmeB3($companyId, $year);
        $devices = Database::one('SELECT COUNT(*) AS total, SUM(is_simulated) AS simulated FROM devices WHERE company_id = ? AND archived_at IS NULL', [$companyId]);
        $monitoringSince = Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE company_id = ?', [$companyId]);
        $iso = static fn (int $ts): string => (string) Time::iso(gmdate('Y-m-d H:i:s', $ts));

        return [
            'version' => 1,
            'generated_at' => $iso($now),
            'company' => [
                'name' => $company['name'], 'legal_form' => $company['legal_form'], 'business_number' => $company['business_number'],
                'nace_code' => $company['nace_code'], 'employees' => $company['employees'] === null ? null : (int) $company['employees'],
                'turnover_eur' => $company['annual_turnover_eur'] === null ? null : (float) $company['annual_turnover_eur'],
                'city' => $company['city'], 'country' => $company['country'], 'demo' => (bool) $company['is_demo'],
            ],
            'period' => ['month' => $month, 'from' => $iso($period->from), 'to' => $iso($period->to), 'days' => round($days, 1), 'partial' => $period->to >= $now],
            'energy' => [
                'kwh' => round($site['kwh'], 1), 'kwh_high' => round($site['kwh_high'], 1), 'kwh_low' => round($site['kwh_low'], 1),
                'eur' => round(EnergyQuery::energyCost($site, $tariff), 2), 'peak_kw' => round($peak, 1), 'bill' => $bill,
            ],
            'previous' => [
                'kwh' => round($previous['kwh'], 1), 'eur' => round(EnergyQuery::energyCost($previous, $tariff), 2), 'co2_kg' => round($previous['kwh'] * $factor['value'], 1),
                'change_ratio' => $previous['kwh'] > 0 ? round($site['kwh'] / $previous['kwh'] - 1, 4) : null,
            ],
            'carbon' => [
                'scope2_location_kg' => $carbon['scope2_location_kg'], 'scope1' => $carbon['scope1'], 'total_kg' => $carbon['total_kg'],
                'intensity_kg_per_k_eur' => $carbon['intensity']['kg_per_k_eur_turnover'], 'scope2_market' => $carbon['scope2_market'],
            ],
            'machines' => $machines,
            'waste' => [
                'kwh' => $waste['totals']['kwh'], 'eur' => $waste['totals']['eur'], 'co2_kg' => $waste['totals']['co2_kg'], 'events' => $waste['totals']['events'],
                'share' => $waste['totals']['share_of_consumption'], 'by_type' => $waste['by_type'], 'by_machine' => array_slice($waste['by_machine'], 0, 5),
                'largest' => $largest === null ? null : [
                    'type' => $largest['type'], 'code' => $largest['code'], 'name' => $largest['name'], 'started_at' => Time::iso($largest['started_at']),
                    'kwh' => (float) $largest['energy_kwh'], 'eur' => (float) $largest['cost_eur'], 'co2_kg' => (float) $largest['co2_kg'],
                ],
            ],
            'actions' => [
                'turn_offs_manual' => (int) ($commands['manual'] ?? 0), 'turn_offs_automatic' => (int) ($commands['automatic'] ?? 0), 'failed' => (int) ($commands['failed'] ?? 0),
                'active_policies' => (int) Database::value('SELECT COUNT(*) FROM automation_policies WHERE company_id = ? AND is_active = 1', [$companyId]),
            ],
            'impact' => [
                'verified' => $impact['verified'],
                'min_reporting_days' => ImpactService::MIN_REPORTING_DAYS,
                'interventions' => array_values(array_map(static fn (array $i): array => [
                    'machine' => $i['machine']['code'], 'label' => $i['label'], 'kind' => $i['kind'], 'verified' => $i['verified'],
                    'collecting' => $i['collecting'], 'grace_min' => $i['policy_params']['grace_min'] ?? null,
                    'reporting_days' => $i['reporting']['days'], 'savings_kwh' => $i['savings']['kwh'], 'ci90_kwh' => $i['savings']['ci90_kwh'],
                    'savings_eur' => $i['savings']['eur'], 'savings_co2_kg' => $i['savings']['co2_kg'],
                ], $impact['interventions'])),
            ],
            'recommendations' => array_slice(array_map(static fn (array $r): array => [
                'title_key' => $r['title_key'], 'params' => $r['params'], 'machine' => $r['machine']['code'] ?? null, 'impact_tag' => $r['impact_tag'],
                'eur_month' => $r['per_month']['eur'], 'kwh_month' => $r['per_month']['kwh'], 'co2_kg_month' => $r['per_month']['co2_kg'], 'effort' => $r['effort'],
            ], Recommendations::list($companyId, 'open')), 0, 5),
            'vsme_b3' => $vsme['rows'],
            // VSME datapoints are annual: the calendar year so far, not this month.
            'vsme_b3_scope' => ['year' => $year, 'from' => $vsme['from'], 'to' => $vsme['to']],
            'readiness' => CarbonReport::readiness($companyId)['score'],
            'data_quality' => [
                'coverage' => $carbon['coverage'],
                'monitoring_since' => $monitoringSince === null ? null : $iso((int) $monitoringSince),
                'devices' => (int) $devices['total'], 'simulated_devices' => (int) $devices['simulated'],
            ],
            'factor' => [
                'value' => $factor['value'], 'unit' => $factor['unit'], 'year' => $factor['reference_year'], 'methodology' => $factor['methodology'],
                'source' => $factor['source_name'], 'url' => $factor['source_url'],
            ],
            'tariff' => $tariff?->summary(),
        ];
    }

    private static function present(array $r, bool $full): array
    {
        $out = [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'title' => $r['title'],
            'period_start' => $r['period_start'],
            'period_end' => $r['period_end'],
            'status' => $r['status'],
            'language' => $r['language'],
            'version' => (int) $r['version'],
            'narrative_source' => $r['narrative_source'],
            'created_by' => $r['created_by_name'],
            'created_at' => Time::iso($r['created_at']),
            'finalized_by' => $r['finalized_by_name'],
            'finalized_at' => Time::iso($r['finalized_at']),
        ];
        if ($full) {
            $out['snapshot'] = json_decode((string) $r['snapshot'], true) ?: [];
            $out['narrative'] = json_decode((string) $r['narrative'], true) ?: [];
        } else {
            $snapshot = json_decode((string) $r['snapshot'], true) ?: [];
            $out['headline'] = ['kwh' => $snapshot['energy']['kwh'] ?? null, 'eur' => $snapshot['energy']['eur'] ?? null, 'co2_kg' => $snapshot['carbon']['total_kg'] ?? null];
        }
        return $out;
    }
}
