<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Core\Validator;
use EnergyFlow\Services\Admin\CompanyDirectory;
use EnergyFlow\Services\Admin\PlatformOverview;
use EnergyFlow\Services\Admin\UserAdmin;
use EnergyFlow\Utils\Locales;

/** The platform admin panel (/admin). Every route sits behind RequirePlatformAdmin. */
final class AdminController
{
    public function requests(Request $request, array $params): Response
    {
        return Response::ok(\EnergyFlow\Core\Database::all('SELECT id, company_name, full_name, email, created_at FROM company_requests ORDER BY created_at'));
    }

    public function approve(Request $request, array $params): Response
    {
        $owner = \EnergyFlow\Core\Database::transaction(static function () use ($request, $params): int {
            $db = \EnergyFlow\Core\Database::class;
            $row = $db::one('SELECT * FROM company_requests WHERE id = ? FOR UPDATE', [(int) $params['id']]);
            if ($row === null) throw \EnergyFlow\Core\HttpException::notFound('request_not_found', 'Request not found.');
            if (\EnergyFlow\Models\User::emailExists($row['email'])) throw \EnergyFlow\Core\HttpException::validation(['email' => 'email_taken']);
            $company = \EnergyFlow\Models\Company::create($row['company_name'], $row['city'], $row['locale']);
            $id = \EnergyFlow\Models\User::create($company, $row['email'], $row['password_hash'], $row['full_name'], 'owner', $row['locale']);
            $db::run('DELETE FROM company_requests WHERE id = ?', [(int) $params['id']]);
            \EnergyFlow\Services\Admin\AdminLog::record(self::admin($request), 'company.approve', 'user', $id);
            return $id;
        });
        return Response::ok(\EnergyFlow\Models\User::find($owner));
    }

    public function overview(Request $request, array $params): Response
    {
        return Response::ok(PlatformOverview::build());
    }

    public function users(Request $request, array $params): Response
    {
        $result = UserAdmin::list([
            'q' => mb_substr((string) $request->query('q', ''), 0, 120),
            'company_id' => (int) $request->query('company_id', '0'),
            'role' => (string) $request->query('role', ''),
            'status' => (string) $request->query('status', ''),
            'page' => (int) $request->query('page', '1'),
        ]);
        return Response::ok($result['users'], ['total' => $result['total'], 'page' => $result['page'], 'page_size' => $result['page_size']]);
    }

    public function updateUser(Request $request, array $params): Response
    {
        $input = Validator::validate($request->json(), [
            'full_name' => ['string', 'min:2', 'max:120'],
            'email' => ['email', 'max:190'],
            'role' => ['in:owner,admin,manager,viewer'],
            'locale' => [Locales::rule()],
            'disabled' => ['bool'],
        ]);
        return Response::ok(UserAdmin::update(self::admin($request), (int) $params['id'], $input));
    }

    public function deleteUser(Request $request, array $params): Response
    {
        return Response::ok(UserAdmin::delete(self::admin($request), (int) $params['id'], $request->query('with_company') === '1'));
    }

    public function sendPasswordReset(Request $request, array $params): Response
    {
        UserAdmin::sendPasswordReset(self::admin($request), (int) $params['id'], $request->ip);
        return Response::ok(['sent' => true]);
    }

    public function companies(Request $request, array $params): Response
    {
        return Response::ok(CompanyDirectory::list(mb_substr((string) $request->query('q', ''), 0, 120)));
    }

    /** @return array{id: int, email: string} */
    private static function admin(Request $request): array
    {
        $user = $request->user();
        return ['id' => (int) $user['id'], 'email' => (string) $user['email']];
    }
}
