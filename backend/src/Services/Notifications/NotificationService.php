<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Notifications;

use EnergyFlow\Core\Database;
use EnergyFlow\Utils\Time;

/**
 * The bell in the top bar. Rows store an i18n key + params (never a rendered
 * sentence), so the same notification reads correctly in Albanian and English.
 * user_id NULL = everyone in the company; read state is per user.
 */
final class NotificationService
{
    public static function push(int $companyId, string $category, string $titleKey, array $params, ?string $link, ?string $sourceType, ?int $sourceId, int $at): int
    {
        return Database::insert(
            'INSERT INTO notifications (company_id, user_id, category, title_key, params, link, source_type, source_id, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))',
            [$companyId, $category, $titleKey, json_encode($params, JSON_UNESCAPED_UNICODE), $link, $sourceType, $sourceId, $at],
        );
    }

    /** @return array{items: list<array<string, mixed>>, unread: int} */
    public static function list(int $companyId, int $userId, int $limit = 30): array
    {
        $rows = Database::all(
            'SELECT n.id, n.category, n.title_key, n.params, n.link, n.source_type, n.source_id, n.created_at, r.read_at
               FROM notifications n
               LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
              WHERE n.company_id = ? AND (n.user_id IS NULL OR n.user_id = ?)
              ORDER BY n.created_at DESC, n.id DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId, $companyId, $userId],
        );
        $items = array_map(static fn (array $n): array => [
            'id' => (int) $n['id'],
            'category' => $n['category'],
            'title_key' => $n['title_key'],
            'params' => json_decode((string) $n['params'], true) ?: [],
            'link' => $n['link'],
            'source' => $n['source_type'] === null ? null : ['type' => $n['source_type'], 'id' => (int) $n['source_id']],
            'created_at' => Time::iso($n['created_at']),
            'read' => $n['read_at'] !== null,
        ], $rows);

        return ['items' => $items, 'unread' => self::unread($companyId, $userId)];
    }

    public static function unread(int $companyId, int $userId): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM notifications n
              LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
              WHERE n.company_id = ? AND (n.user_id IS NULL OR n.user_id = ?) AND r.read_at IS NULL',
            [$userId, $companyId, $userId],
        );
    }

    public static function markRead(int $companyId, int $userId, int $notificationId): bool
    {
        $exists = Database::value('SELECT 1 FROM notifications WHERE id = ? AND company_id = ?', [$notificationId, $companyId]);
        if ($exists === null) {
            return false;
        }
        Database::run(
            'INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at) VALUES (?, ?, UTC_TIMESTAMP())',
            [$notificationId, $userId],
        );
        return true;
    }

    public static function markAllRead(int $companyId, int $userId): int
    {
        return Database::run(
            'INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
             SELECT n.id, ?, UTC_TIMESTAMP() FROM notifications n
              WHERE n.company_id = ? AND (n.user_id IS NULL OR n.user_id = ?)',
            [$userId, $companyId, $userId],
        );
    }
}
