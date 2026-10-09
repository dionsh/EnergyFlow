<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Admin;

use EnergyFlow\Core\Database;
use EnergyFlow\Utils\Time;

/** Every company on EnergyFlow, with what it has set up and when it last sent data. */
final class CompanyDirectory
{
    /** @return list<array<string, mixed>> real companies first, newest first */
    public static function list(string $q = ''): array
    {
        $where = '1 = 1';
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = '(c.name LIKE ? OR c.city LIKE ? OR c.business_number LIKE ?)';
            $params = [$like, $like, $like];
        }
        $rows = Database::all(
            "SELECT c.id, c.name, c.legal_form, c.business_number, c.nace_code, c.employees, c.city, c.country, c.is_demo, c.created_at,
                    (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS users,
                    (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.disabled_at IS NULL) AS active_users,
                    (SELECT COUNT(*) FROM machines m WHERE m.company_id = c.id AND m.kind = 'machine' AND m.archived_at IS NULL) AS machines,
                    (SELECT COUNT(*) FROM devices d WHERE d.company_id = c.id AND d.archived_at IS NULL) AS devices,
                    (SELECT COUNT(*) FROM devices d WHERE d.company_id = c.id AND d.archived_at IS NULL AND d.is_simulated = 1) AS simulated_devices,
                    (SELECT MAX(r.bucket_start) FROM readings_15m r WHERE r.company_id = c.id) AS last_data_at,
                    (SELECT COUNT(*) FROM reports rp WHERE rp.company_id = c.id) AS reports,
                    (SELECT u.email FROM users u WHERE u.company_id = c.id AND u.role = 'owner' ORDER BY u.disabled_at IS NOT NULL, u.id LIMIT 1) AS owner_email
               FROM companies c WHERE {$where}
              ORDER BY c.is_demo, c.created_at DESC, c.id DESC LIMIT 500",
            $params,
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'legal_form' => $r['legal_form'],
            'business_number' => $r['business_number'],
            'nace_code' => $r['nace_code'],
            'employees' => $r['employees'] === null ? null : (int) $r['employees'],
            'city' => $r['city'],
            'country' => $r['country'],
            'demo' => (bool) $r['is_demo'],
            'users' => (int) $r['users'],
            'active_users' => (int) $r['active_users'],
            'owner_email' => $r['owner_email'],
            'machines' => (int) $r['machines'],
            'devices' => (int) $r['devices'],
            'simulated_devices' => (int) $r['simulated_devices'],
            'reports' => (int) $r['reports'],
            'last_data_at' => Time::iso($r['last_data_at']),
            'created_at' => Time::iso($r['created_at']),
        ], $rows);
    }
}
