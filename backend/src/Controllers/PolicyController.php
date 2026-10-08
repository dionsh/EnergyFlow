<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\AuditLog;
use EnergyFlow\Utils\Time;

/** Automation policies: what EnergyFlow may do on its own, and how often it did. */
final class PolicyController
{
    public function index(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $rows = Database::all(
            "SELECT p.id, p.machine_id, p.recommendation_id, p.type, p.params, p.mode, p.is_active, p.effective_from, p.created_at,
                    m.code, m.name, m.type_code, u.full_name AS created_by,
                    (SELECT COUNT(*) FROM device_commands c WHERE c.policy_id = p.id) AS triggered,
                    (SELECT COUNT(*) FROM device_commands c WHERE c.policy_id = p.id AND c.status = 'verified') AS verified,
                    (SELECT MAX(c.requested_at) FROM device_commands c WHERE c.policy_id = p.id) AS last_triggered_at,
                    (SELECT JSON_OBJECT('savings_kwh', i.savings_kwh, 'savings_eur', i.savings_eur, 'savings_co2_kg', i.savings_co2_kg,
                                        'ci90_kwh', i.savings_ci90_kwh, 'is_verified', i.is_verified, 'reporting_days', DATEDIFF(i.reporting_end, i.reporting_start))
                       FROM impact_verifications i WHERE i.policy_id = p.id ORDER BY i.computed_at DESC LIMIT 1) AS impact
               FROM automation_policies p
               JOIN machines m ON m.id = p.machine_id
               LEFT JOIN users u ON u.id = p.created_by
              WHERE p.company_id = ?
              ORDER BY p.is_active DESC, p.effective_from DESC",
            [$companyId],
        );
        return Response::ok(array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'type' => $p['type'],
            'params' => json_decode((string) $p['params'], true) ?: [],
            'mode' => $p['mode'],
            'active' => (bool) $p['is_active'],
            'machine' => ['id' => (int) $p['machine_id'], 'code' => $p['code'], 'name' => $p['name'], 'type' => $p['type_code']],
            'recommendation_id' => $p['recommendation_id'] === null ? null : (int) $p['recommendation_id'],
            'effective_from' => Time::iso($p['effective_from']),
            'created_by' => $p['created_by'],
            'triggered' => (int) $p['triggered'],
            'verified' => (int) $p['verified'],
            'last_triggered_at' => Time::iso($p['last_triggered_at']),
            'impact' => $p['impact'] === null ? null : json_decode((string) $p['impact'], true),
        ], $rows));
    }

    public function update(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'active' => ['nullable', 'bool'],
            'mode' => ['nullable', 'in:notify,approve,auto'],
            'grace_min' => ['nullable', 'int', 'min:0', 'max:240'],
        ]);
        $companyId = $request->companyId();
        $id = (int) $params['id'];
        $policy = Database::one('SELECT params FROM automation_policies WHERE id = ? AND company_id = ?', [$id, $companyId])
            ?? throw HttpException::notFound('policy_not_found', 'Policy not found.');

        if (array_key_exists('active', $input) && $input['active'] !== null) {
            Database::run('UPDATE automation_policies SET is_active = ? WHERE id = ?', [$input['active'] ? 1 : 0, $id]);
        }
        if (!empty($input['mode'])) {
            Database::run('UPDATE automation_policies SET mode = ? WHERE id = ?', [$input['mode'], $id]);
        }
        if (isset($input['grace_min'])) {
            $policyParams = json_decode((string) $policy['params'], true) ?: [];
            $policyParams['grace_min'] = $input['grace_min'];
            Database::run('UPDATE automation_policies SET params = ? WHERE id = ?', [json_encode($policyParams), $id]);
        }
        AuditLog::record($companyId, (int) $request->user()['id'], 'policy.update', 'automation_policy', $id, $input);
        return $this->index($request, $params);
    }
}
