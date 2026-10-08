<?php

declare(strict_types=1);

namespace EnergyFlow\Controllers;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;
use EnergyFlow\Core\Response;
use EnergyFlow\Services\Notifications\NotificationService;

final class NotificationController
{
    public function index(Request $request, array $params): Response
    {
        $user = $request->user();
        return Response::ok(NotificationService::list($request->companyId(), (int) $user['id']));
    }

    public function read(Request $request, array $params): Response
    {
        if (!NotificationService::markRead($request->companyId(), (int) $request->user()['id'], (int) $params['id'])) {
            throw HttpException::notFound('notification_not_found', 'Notification not found.');
        }
        return Response::ok(['unread' => NotificationService::unread($request->companyId(), (int) $request->user()['id'])]);
    }

    public function readAll(Request $request, array $params): Response
    {
        NotificationService::markAllRead($request->companyId(), (int) $request->user()['id']);
        return Response::ok(['unread' => 0]);
    }
}
