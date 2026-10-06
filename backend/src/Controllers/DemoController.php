<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Database;
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

/** The Demo Director panel: virtual clock, fast-forward and story reset. */
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
        $input = Validator::validate($request->json(), ['hours' => ['required', 'int', 'min:1', 'max:744']]);
        $rows = DemoClock::advance($request->companyId(), $input['hours'] * 3600);
        return $this->state($request, $params)->withHeader('X-EF-Generated-Rows', (string) $rows);
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
