<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Scan;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Services\Analytics\EnergyQuery;
use EnergyFlow\Services\Analytics\OverviewService;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Services\Calendar\LocalTime;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Tariff\TariffBook;
use EnergyFlow\Utils\Time;

/**
 * Checks and stores what was scanned (or typed in): a bill, a utility meter
 * reading, a nameplate on a machine, a fuel receipt as Scope 1 activity data.
 * The fields saved are the ones the user confirmed, re-checked here.
 */
final class ScanService
{
    public static function check(int $companyId, string $kind, array $fields, ?int $machineId): array
    {
        $f = ScanReader::normalise($kind, $fields);
        return match ($kind) {
            'bill' => ScanCheck::bill($companyId, $f),
            'meter' => ScanCheck::meter($companyId, $f),
            'nameplate' => ScanCheck::nameplate($companyId, $f, $machineId),
            'fuel' => ScanCheck::fuel($companyId, $f),
            'label' => ScanCheck::label($companyId, $f),
            default => throw HttpException::validation(['kind' => 'invalid_choice']),
        };
    }

    /** @return array{check: array, saved: array} */
    public static function save(int $companyId, int $userId, string $kind, array $fields, ?int $machineId, string $source): array
    {
        $f = ScanReader::normalise($kind, $fields);
        $check = self::check($companyId, $kind, $f, $machineId);
        $now = Clock::now($companyId);
        $saved = match ($kind) {
            'bill' => self::saveBill($companyId, $userId, $f, $check, $source, $now),
            'meter' => self::saveReading($companyId, $userId, $f, $check, $source, $now),
            'nameplate' => self::saveNameplate($companyId, $userId, $f, $machineId),
            'fuel' => self::saveFuel($companyId, $userId, $f, $check, $source, $now),
            default => throw HttpException::badRequest('not_savable', 'This kind of scan is for information only.'),
        };
        return ['check' => $check, 'saved' => $saved];
    }

    private static function saveBill(int $companyId, int $userId, array $f, array $check, string $source, int $now): array
    {
        $kwh = $check['derived']['kwh'];
        $month = $check['derived']['period_month'];
        if ($month === null || $kwh === null || $f['total_eur'] === null) {
            throw HttpException::validation(array_filter([
                'period_end' => $month === null ? 'required' : null, 'kwh_total' => $kwh === null ? 'required' : null, 'total_eur' => $f['total_eur'] === null ? 'required' : null,
            ]));
        }
        $checks = array_map(static fn (array $c): array => ['key' => $c['key'], 'status' => $c['status'], 'params' => $c['params']], $check['checks']);
        Database::run(
            "INSERT INTO utility_bills (company_id, period_month, supplier, issue_date, period_start, period_end, kwh_high, kwh_low, kwh_total, amount_eur, net_eur, vat_eur,
                                        verdict, checks, source, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE supplier = VALUES(supplier), issue_date = VALUES(issue_date), period_start = VALUES(period_start), period_end = VALUES(period_end),
               kwh_high = VALUES(kwh_high), kwh_low = VALUES(kwh_low), kwh_total = VALUES(kwh_total), amount_eur = VALUES(amount_eur), net_eur = VALUES(net_eur),
               vat_eur = VALUES(vat_eur), verdict = VALUES(verdict), checks = VALUES(checks), source = VALUES(source), created_by = VALUES(created_by), created_at = VALUES(created_at)",
            [$companyId, $month, $check['derived']['supplier'] ?? $f['supplier'], $f['issue_date'], $f['period_start'], $f['period_end'], $f['kwh_high'], $f['kwh_low'], $kwh,
             $f['total_eur'], $f['net_eur'], $f['vat_eur'], $check['verdict'], json_encode($checks, JSON_UNESCAPED_UNICODE), $source, $userId, $now],
        );
        $id = (int) Database::value('SELECT id FROM utility_bills WHERE company_id = ? AND period_month = ?', [$companyId, $month]);
        AuditLog::record($companyId, $userId, 'bill.save', 'utility_bill', $id, ['month' => substr($month, 0, 7), 'verdict' => $check['verdict'], 'source' => $source]);
        return self::bills($companyId, $id)[0];
    }

    private static function saveReading(int $companyId, int $userId, array $f, array $check, string $source, int $now): array
    {
        $reading = $check['derived']['reading_kwh'];
        if ($reading === null) {
            throw HttpException::validation(['reading_kwh' => 'required']);
        }
        if (array_filter($check['checks'], static fn (array $c): bool => $c['key'] === 'reading_backwards') !== []) {
            throw HttpException::conflict('reading_backwards', 'The reading is lower than the previous one.');
        }
        $id = Database::insert(
            'INSERT INTO meter_readings (company_id, read_at, meter_serial, register_code, reading_kwh, source, created_by, created_at)
             VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, FROM_UNIXTIME(?))',
            [$companyId, $now, $f['meter_serial'], $check['derived']['register'], $reading, $source, $userId, $now],
        );
        AuditLog::record($companyId, $userId, 'meter_reading.save', 'meter_reading', $id, ['register' => $check['derived']['register'], 'source' => $source]);
        return self::readings($companyId)[0];
    }

    private static function saveNameplate(int $companyId, int $userId, array $f, ?int $machineId): array
    {
        $machine = $machineId === null ? null : Database::one('SELECT id, code FROM machines WHERE company_id = ? AND id = ? AND archived_at IS NULL', [$companyId, $machineId]);
        if ($machine === null) {
            throw HttpException::validation(['machine_id' => 'required']);
        }
        $kw = $f['rated_power_kw'] ?? ($f['rated_power_hp'] !== null ? round($f['rated_power_hp'] * 0.7457, 2) : null);
        $plate = array_filter([
            'serial' => $f['serial'], 'rated_power_hp' => $f['rated_power_hp'], 'voltage_v' => $f['voltage_v'], 'current_a' => $f['current_a'],
            'frequency_hz' => $f['frequency_hz'], 'power_factor' => $f['power_factor'], 'efficiency_class' => $f['efficiency_class'],
            'efficiency_pct' => $f['efficiency_pct'], 'speed_rpm' => $f['speed_rpm'],
        ], static fn (mixed $v): bool => $v !== null);
        Database::run(
            'UPDATE machines SET manufacturer = COALESCE(?, manufacturer), model = COALESCE(?, model), year_installed = COALESCE(?, year_installed),
                    rated_power_kw = COALESCE(?, rated_power_kw), phases = COALESCE(?, phases), nameplate = ? WHERE id = ?',
            [$f['manufacturer'], $f['model'], $f['year'] === null ? null : (int) $f['year'], $kw, $f['phases'] === null ? null : (int) $f['phases'],
             $plate === [] ? null : json_encode($plate), $machineId],
        );
        AuditLog::record($companyId, $userId, 'machine.nameplate', 'machine', $machineId, ['rated_power_kw' => $kw]);
        return Database::one('SELECT id, code, name, manufacturer, model, year_installed, rated_power_kw, phases, nameplate FROM machines WHERE id = ?', [$machineId])
            + ['nameplate_saved' => true];
    }

    private static function saveFuel(int $companyId, int $userId, array $f, array $check, string $source, int $now): array
    {
        $fuel = $check['derived']['fuel'];
        $factor = $fuel === null ? null : ScanCheck::fuelFactor($fuel);
        if ($factor === null || $f['litres'] === null || $f['litres'] <= 0) {
            throw HttpException::validation(array_filter(['fuel' => $factor === null ? 'invalid_choice' : null, 'litres' => $f['litres'] === null ? 'required' : null]));
        }
        $time = LocalTime::forCompany($companyId);
        $month = ($f['date'] ?? $time->date($now));
        $month = substr($month, 0, 7) . '-01';
        $note = mb_substr(trim(($f['vendor'] ?? '') . ($f['date'] !== null ? ' · ' . $f['date'] : '')), 0, 200) ?: null;
        $id = Database::insert(
            "INSERT INTO activity_data (company_id, period_month, activity, quantity, unit, emission_factor_id, co2e_kg, energy_kwh, evidence_note, source, created_by, created_at)
             VALUES (?, ?, ?, ?, 'l', ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))",
            [$companyId, $month, $fuel, $f['litres'], (int) $factor['id'], $check['derived']['co2_kg'], $check['derived']['energy_kwh'], $note, $source, $userId, $now],
        );
        AuditLog::record($companyId, $userId, 'scope1.add', 'activity_data', $id, ['fuel' => $fuel, 'litres' => $f['litres'], 'source' => $source]);
        return self::fuelRecords($companyId)[0];
    }

    // ---------------------------------------------------------------- lists

    /**
     * Bills next to what EnergyFlow measured for the same period and what the
     * official tariff gives for the billed kWh ("utility bills → coverage").
     */
    public static function bills(int $companyId, ?int $id = null): array
    {
        $time = LocalTime::forCompany($companyId);
        $tariff = TariffBook::forCompany($companyId);
        $incomer = OverviewService::incomerId($companyId);
        $since = Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE company_id = ?', [$companyId]);
        $rows = Database::all(
            'SELECT b.*, u.full_name AS created_by_name FROM utility_bills b LEFT JOIN users u ON u.id = b.created_by
              WHERE b.company_id = ?' . ($id === null ? '' : ' AND b.id = ?') . ' ORDER BY b.period_month DESC',
            $id === null ? [$companyId] : [$companyId, $id],
        );
        return array_map(static function (array $b) use ($companyId, $time, $tariff, $incomer, $since): array {
            $from = $b['period_start'] !== null ? $time->at($b['period_start']) : $time->at($b['period_month']);
            $to = $b['period_end'] !== null ? $time->at($b['period_end']) + 86400 : $time->startOfMonth($time->at($b['period_month']) + 32 * 86400);
            $covered = $since !== null && (int) $since <= $from + 86400;
            $metered = $covered ? EnergyQuery::site(EnergyQuery::byMachine($companyId, $from, $to, null, $tariff), $incomer)['kwh'] : null;
            return [
                'id' => (int) $b['id'],
                'month' => substr($b['period_month'], 0, 7),
                'supplier' => $b['supplier'],
                'issue_date' => $b['issue_date'],
                'period_start' => $b['period_start'],
                'period_end' => $b['period_end'],
                'kwh' => (float) $b['kwh_total'],
                'kwh_high' => $b['kwh_high'] === null ? null : (float) $b['kwh_high'],
                'kwh_low' => $b['kwh_low'] === null ? null : (float) $b['kwh_low'],
                'total_eur' => (float) $b['amount_eur'],
                'net_eur' => $b['net_eur'] === null ? null : (float) $b['net_eur'],
                'vat_eur' => $b['vat_eur'] === null ? null : (float) $b['vat_eur'],
                'metered_kwh' => $metered === null ? null : round($metered, 1),
                // Share of the billed kWh that EnergyFlow measured (the site incomer, or the sum of metered machines).
                'coverage' => $metered === null || (float) $b['kwh_total'] <= 0 ? null : round($metered / (float) $b['kwh_total'], 4),
                'verdict' => $b['verdict'],
                'checks' => json_decode((string) $b['checks'], true) ?: [],
                'source' => $b['source'],
                'created_by' => $b['created_by_name'],
                'created_at' => Time::iso($b['created_at']),
            ];
        }, $rows);
    }

    public static function readings(int $companyId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'read_at' => Time::iso($r['read_at']), 'meter_serial' => $r['meter_serial'], 'register' => $r['register_code'],
            'reading_kwh' => (float) $r['reading_kwh'], 'source' => $r['source'], 'created_by' => $r['created_by_name'],
        ], Database::all(
            'SELECT m.*, u.full_name AS created_by_name FROM meter_readings m LEFT JOIN users u ON u.id = m.created_by WHERE m.company_id = ? ORDER BY m.read_at DESC LIMIT 50',
            [$companyId],
        ));
    }

    public static function fuelRecords(int $companyId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'month' => substr($r['period_month'], 0, 7), 'fuel' => $r['activity'], 'litres' => (float) $r['quantity'],
            'co2_kg' => (float) $r['co2e_kg'], 'energy_kwh' => $r['energy_kwh'] === null ? null : (float) $r['energy_kwh'],
            'note' => $r['evidence_note'], 'source' => $r['source'], 'factor' => ['value' => (float) $r['factor_value'], 'source' => $r['factor_source']],
            'created_by' => $r['created_by_name'], 'created_at' => Time::iso($r['created_at']),
        ], Database::all(
            'SELECT a.*, f.value AS factor_value, f.source_name AS factor_source, u.full_name AS created_by_name
               FROM activity_data a JOIN emission_factors f ON f.id = a.emission_factor_id LEFT JOIN users u ON u.id = a.created_by
              WHERE a.company_id = ? ORDER BY a.period_month DESC, a.id DESC LIMIT 100',
            [$companyId],
        ));
    }

    public static function delete(int $companyId, int $userId, string $what, int $id): void
    {
        [$table, $entity] = match ($what) {
            'bill' => ['utility_bills', 'utility_bill'],
            'reading' => ['meter_readings', 'meter_reading'],
            'fuel' => ['activity_data', 'activity_data'],
        };
        if (Database::run("DELETE FROM {$table} WHERE company_id = ? AND id = ?", [$companyId, $id]) === 0) {
            throw HttpException::notFound('not_found', 'Not found.');
        }
        AuditLog::record($companyId, $userId, $entity . '.delete', $entity, $id);
    }
}
