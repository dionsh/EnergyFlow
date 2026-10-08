<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Carbon;

use EnergyFlow\Core\Database;
use EnergyFlow\Services\Calendar\LocalTime;

/**
 * The daily emissions ledger: one row per machine (and the main incomer) per
 * complete local day, with the kWh, the kg CO₂e and the id of the emission
 * factor used. Reports read the ledger, so a later change of factor never
 * silently rewrites what was already reported.
 */
final class CarbonLedger
{
    private const JOB = 'carbon_ledger';

    public static function run(int $companyId, int $now): int
    {
        $time = LocalTime::forCompany($companyId);
        $today = $time->startOfDay($now);
        $watermark = Database::value('SELECT UNIX_TIMESTAMP(watermark) FROM job_runs WHERE job_key = ? AND company_id = ?', [self::JOB, $companyId]);
        $from = $watermark === null
            ? Database::value('SELECT UNIX_TIMESTAMP(MIN(bucket_start)) FROM readings_15m WHERE company_id = ?', [$companyId])
            : (int) $watermark;
        if ($from === null || (int) $from >= $today) {
            return 0;
        }
        $from = $time->startOfDay((int) $from);

        $factor = EmissionFactors::gridFactor($companyId);
        $days = [];
        foreach (Database::all(
            'SELECT machine_id, UNIX_TIMESTAMP(bucket_start) AS t, kwh FROM readings_15m
              WHERE company_id = ? AND bucket_start >= FROM_UNIXTIME(?) AND bucket_start < FROM_UNIXTIME(?)',
            [$companyId, $from, $today],
        ) as $b) {
            $key = $b['machine_id'] . '|' . $time->date((int) $b['t']);
            $days[$key] = ($days[$key] ?? 0.0) + (float) $b['kwh'];
        }

        foreach (array_chunk($days, 500, true) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $key => $kwh) {
                [$machineId, $date] = explode('|', (string) $key);
                $placeholders[] = '(?, ?, ?, ?, ?, ?)';
                array_push($params, $companyId, (int) $machineId, $date, round($kwh, 3), round($kwh * $factor['value'], 3), $factor['id']);
            }
            Database::run(
                'INSERT INTO carbon_records (company_id, machine_id, date, kwh, co2e_kg, emission_factor_id) VALUES ' . implode(',', $placeholders) . '
                 ON DUPLICATE KEY UPDATE kwh = VALUES(kwh), co2e_kg = VALUES(co2e_kg), emission_factor_id = VALUES(emission_factor_id)',
                $params,
            );
        }

        Database::run(
            "INSERT INTO job_runs (job_key, company_id, watermark, last_started_at, last_finished_at, last_status)
             VALUES (?, ?, FROM_UNIXTIME(?), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok')
             ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), last_finished_at = VALUES(last_finished_at), last_status = 'ok'",
            [self::JOB, $companyId, $today],
        );
        return count($days);
    }
}
