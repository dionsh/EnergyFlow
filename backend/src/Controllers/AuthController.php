<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Models\Company;
use EnergyFlow\Models\User;
use EnergyFlow\Services\Auth\AuthService;
use EnergyFlow\Services\Auth\SessionService;
use EnergyFlow\Utils\Locales;

final class AuthController
{
    public function register(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:10', 'max:200'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'locale' => ['nullable', Locales::rule()],
        ]);

        $result = AuthService::register($input, $request);
        return SessionService::attachCookie(
            Response::created(['user' => $result['user'], 'company' => $result['company']]),
            $result['token'],
        );
    }

    public function login(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $result = AuthService::login($input['email'], $input['password'], $request);
        return SessionService::attachCookie(
            Response::ok(['user' => $result['user'], 'company' => $result['company']]),
            $result['token'],
        );
    }

    public function logout(Request $request, array $params): Response
    {
        $token = $request->cookie(SessionService::COOKIE);
        if ($token !== null) {
            SessionService::revoke($token);
        }
        return SessionService::clearCookie(Response::ok(null));
    }

    public function me(Request $request, array $params): Response
    {
        $user = $request->user();
        return Response::ok(['user' => $user, 'company' => Company::find($user['company_id'])]);
    }

    public function updateMe(Request $request, array $params): Response
    {
        $user = $request->user();
        $input = Validator::validate($request->json(), [
            'full_name' => ['string', 'min:2', 'max:120'],
            'locale' => [Locales::rule()],
            'current_password' => ['string', 'max:200'],
            'new_password' => ['string', 'min:10', 'max:200'],
        ]);

        if (isset($input['new_password'])) {
            if (!isset($input['current_password'])) {
                throw HttpException::validation(['current_password' => 'required']);
            }
            AuthService::changePassword($user['id'], $input['current_password'], $input['new_password'], (string) $request->cookie(SessionService::COOKIE));
        }
        User::updateProfile($user['id'], $input);

        return Response::ok(['user' => User::find($user['id']), 'company' => Company::find($user['company_id'])]);
    }
}
