<?php

declare(strict_types=1);

namespace EnergyFlow\Services\Scan;

/** The checks run on one scanned document, in order. */
final class CheckList
{
    /** @var list<array{key: string, status: string, params: array<string, mixed>, integrity: bool}> */
    private array $checks = [];

    /** $integrity: a failure that a genuine document can't have (its own numbers don't add up). */
    public function add(string $key, string $status, array $params = [], bool $integrity = false): void
    {
        $this->checks[] = ['key' => $key, 'status' => $status, 'params' => $params, 'integrity' => $integrity];
    }

    public function has(string $status): bool
    {
        return in_array($status, array_column($this->checks, 'status'), true);
    }

    public function integrityFailures(): int
    {
        return count(array_filter($this->checks, static fn (array $c): bool => $c['status'] === 'fail' && $c['integrity']));
    }

    public function result(string $kind, string $verdict, array $derived): array
    {
        return ['kind' => $kind, 'verdict' => $verdict, 'checks' => $this->checks, 'derived' => $derived];
    }
}
