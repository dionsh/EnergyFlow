<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Models\Company;
use EnergyFlow\Models\User;
use EnergyFlow\Services\Auth\SessionService;
use EnergyFlow\Services\Clock;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Services\Demo\DemoSeeder;
use EnergyFlow\Utils\Time;

/** The Demo Director panel: virtual clock, fast-forward, fault injection and story reset. */
final class DemoController
{
    public function state(Request $request, array $params): Response
    {
        $companyId = $request->companyId();
        $state = Database::one(
            'SELECT clock_offset_s, UNIX_TIMESTAMP(story_anchor) AS anchor, UNIX_TIMESTAMP(last_generated_at) AS generated FROM sim_state WHERE company_id = ?',
            [$companyId],
        );
        $now = Clock::now($companyId);
        return Response::ok([
            'virtual_now' => Time::iso(gmdate('Y-m-d H:i:s', $now)),
            'offset_s' => (int) $state['clock_offset_s'],
            'story_anchor' => Time::iso(gmdate('Y-m-d H:i:s', (int) $state['anchor'])),
            'generated_until' => Time::iso(gmdate('Y-m-d H:i:s', (int) $state['generated'])),
        ]);
    }

    /** Fast-forward: generates the skipped days with everything accepted so far applied. */
    public function advance(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'hours' => ['nullable', 'int', 'min:1', 'max:744'],
            'minutes' => ['nullable', 'int', 'min:1', 'max:120'],
        ]);
        $seconds = ($input['hours'] ?? 0) * 3600 + ($input['minutes'] ?? 0) * 60;
        if ($seconds === 0) {
            throw HttpException::validation(['hours' => 'required']);
        }
        $rows = DemoClock::advance($request->companyId(), $seconds);
        return $this->state($request, $params)->withHeader('X-EF-Generated-Rows', (string) $rows);
    }

    /**
     * Injects a motor overload (scenario 'spike') starting at the virtual now: the
     * machine draws `percent` % of its nameplate power for `minutes`, and the spike
     * detector has to find it in the data like any other fault. Without machine_id,
     * the motor-driven machine drawing the most power right now is chosen.
     */
    public function spike(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'machine_id' => ['nullable', 'int', 'min:1'],
            'minutes' => ['nullable', 'int', 'min:3', 'max:30'],
            'percent' => ['nullable', 'int', 'min:100', 'max:200'],
        ]);
        $companyId = $request->companyId();
        DemoClock::catchUp($companyId);
        $machine = Database::one(
            "SELECT m.id, m.code, m.name, m.rated_power_kw, l.power_kw FROM machines m LEFT JOIN machine_live l ON l.machine_id = m.id
              WHERE m.company_id = ? AND m.kind = 'machine' AND m.archived_at IS NULL AND m.rated_power_kw > 0"
                // Chosen automatically: motor-driven loads only (lighting and IT have no motor to overload).
                . (isset($input['machine_id']) ? ' AND m.id = ?' : " AND m.type_code NOT IN ('lighting', 'office')")
                . ' ORDER BY l.power_kw DESC LIMIT 1',
            isset($input['machine_id']) ? [$companyId, $input['machine_id']] : [$companyId],
        );
        if ($machine === null) {
            throw HttpException::notFound('machine_not_found', 'No such machine.');
        }
        if ((float) ($machine['power_kw'] ?? 0) <= 0.05) {
            throw HttpException::conflict('machine_not_running', 'The machine is off: a fault shows only while it draws power.');
        }

        $now = Clock::now($companyId);
        $start = $now - $now % 10 + 10; // from the next reading
        $minutes = $input['minutes'] ?? 6;
        $share = ($input['percent'] ?? 135) / 100;
        Database::run(
            "INSERT INTO sim_scenarios (company_id, scenario, machine_id, params, starts_at, ends_at)
             VALUES (?, 'spike', ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))",
            [$companyId, (int) $machine['id'], json_encode(['rated_share' => $share]), $start, $start + $minutes * 60],
        );
        return Response::ok([
            'machine' => ['id' => (int) $machine['id'], 'code' => $machine['code'], 'name' => $machine['name']],
            'rated_kw' => (float) $machine['rated_power_kw'],
            'kw' => round($share * (float) $machine['rated_power_kw'], 2),
            'percent' => (int) round($share * 100),
            'starts_at' => Time::iso(gmdate('Y-m-d H:i:s', $start)),
            'ends_at' => Time::iso(gmdate('Y-m-d H:i:s', $start + $minutes * 60)),
        ]);
    }

    /**
     * Rebuilds the whole demo story. The demo company (and its users) is recreated,
     * so the presenter gets a fresh session for the new owner account.
     */
    public function reset(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), ['scene' => ['nullable', 'in:weekday_evening,now']]);
        $anchor = DemoClock::sceneAnchor($input['scene'] ?? 'weekday_evening');
        $result = DemoSeeder::reset($anchor);

        $ownerId = (int) Database::value('SELECT id FROM users WHERE email = ?', [DemoSeeder::OWNER_EMAIL]);
        $token = SessionService::start($ownerId, $request);
        $user = User::find($ownerId);
        return SessionService::attachCookie(
            Response::ok(['user' => $user, 'company' => Company::find($result['company_id'])]),
            $token,
        );
    }
}
