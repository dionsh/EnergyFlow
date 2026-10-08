<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\Assistant\AssistantService;
use EnergyFlow\Services\Auth\SessionService;
use EnergyFlow\Services\Demo\DemoClock;
use EnergyFlow\Utils\Locales;

/** Ask EnergyFlow (docs/05-api.md "Assistant"). Every role can ask; acting still needs the role the action needs. */
final class AssistantController
{
    public function index(Request $request, array $params): Response
    {
        return Response::ok(AssistantService::conversations($request->companyId(), $request->user(), $this->sessionKey($request)));
    }

    public function create(Request $request, array $params): Response
    {
        return Response::created(AssistantService::create($request->companyId(), $request->user(), $this->sessionKey($request)));
    }

    public function show(Request $request, array $params): Response
    {
        return Response::ok(AssistantService::show($request->companyId(), $request->user(), $this->sessionKey($request), (int) $params['id']));
    }

    public function destroy(Request $request, array $params): Response
    {
        AssistantService::delete($request->companyId(), $request->user(), $this->sessionKey($request), (int) $params['id']);
        return Response::noContent();
    }

    public function message(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'content' => ['required', 'string', 'max:1000'],
            'language' => ['nullable', Locales::rule()],
            'context' => ['nullable'],
        ]);
        $companyId = $this->fresh($request);
        $user = $request->user();
        return Response::ok(AssistantService::ask(
            $companyId,
            $user,
            $this->sessionKey($request),
            (int) $params['id'],
            (string) $input['content'],
            self::context($input['context'] ?? null),
            $input['language'] ?? (string) ($user['locale'] ?? Locales::DEFAULT),
            $request->ip,
        ));
    }

    public function action(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'state' => ['required', 'in:confirmed,cancelled'],
            'command_id' => ['nullable', 'int'],
        ]);
        return Response::ok(AssistantService::resolveAction(
            $request->companyId(),
            $request->user(),
            $this->sessionKey($request),
            (int) $params['id'],
            $input['state'],
            isset($input['command_id']) ? (int) $input['command_id'] : null,
        ));
    }

    public function suggestions(Request $request, array $params): Response
    {
        $input = Validator::validate($request->query, [
            'language' => ['nullable', Locales::rule()],
            'page' => ['nullable', 'string', 'max:40'],
            'machine_id' => ['nullable', 'int'],
        ]);
        $companyId = $this->fresh($request);
        $user = $request->user();
        return Response::ok(AssistantService::suggestions(
            $companyId,
            $user,
            $input['language'] ?? (string) ($user['locale'] ?? Locales::DEFAULT),
            self::context(['page' => $input['page'] ?? null, 'machine_id' => $input['machine_id'] ?? null]),
        ));
    }

    /** Only known keys, as scalars: the page context is a hint, never trusted input. */
    private static function context(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        if (is_string($raw['page'] ?? null) && preg_match('/^[a-z_-]{1,40}$/', $raw['page'])) {
            $out['page'] = $raw['page'];
        }
        foreach (['machine_id', 'waste_event_id', 'recommendation_id'] as $key) {
            if (isset($raw[$key]) && filter_var($raw[$key], FILTER_VALIDATE_INT) !== false && (int) $raw[$key] > 0) {
                $out[$key] = (int) $raw[$key];
            }
        }
        return $out;
    }

    /** A per-browser key (a hash of the session cookie, never the token itself). */
    private function sessionKey(Request $request): ?string
    {
        $token = $request->cookie(SessionService::COOKIE);
        return $token === null ? null : hash('sha256', 'assistant|' . $token);
    }

    private function fresh(Request $request): int
    {
        $companyId = $request->companyId();
        if (DemoClock::isDemo($companyId)) {
            DemoClock::catchUp($companyId);
        }
        return $companyId;
    }
}
