<?php

declare(strict_types=1);

namespace EnergyFlow\Middleware;

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Request;

/** Roles are ordered: viewer < manager < admin < owner. */
final class RequireRole implements Middleware
{
    private const RANK = ['viewer' => 1, 'manager' => 2, 'admin' => 3, 'owner' => 4];

    public function __construct(private readonly string $minimum)
    {
    }

    public static function atLeast(string $role, string $minimum): bool
    {
        return (self::RANK[$role] ?? 0) >= self::RANK[$minimum];
    }

    public function handle(Request $request): void
    {
        if (!self::atLeast((string) $request->user()['role'], $this->minimum)) {
            throw HttpException::forbidden();
        }
    }
}
