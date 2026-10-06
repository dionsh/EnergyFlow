<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

/**
 * JSON response with the API envelope:
 *   success → { "data": …, "meta": { "server_time": … } }
 *   error   → { "error": { "code", "message", "fields" } }
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    public function __construct(public readonly mixed $body, public readonly int $status = 200)
    {
    }

    public static function ok(mixed $data, array $meta = [], int $status = 200): self
    {
        return new self(['data' => $data, 'meta' => ['server_time' => gmdate('Y-m-d\TH:i:s\Z')] + $meta], $status);
    }

    public static function created(mixed $data, array $meta = []): self
    {
        return self::ok($data, $meta, 201);
    }

    public static function noContent(): self
    {
        return new self(null, 204);
    }

    /** @param array<string, string> $fields */
    public static function error(string $code, string $message, int $status, array $fields = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        return new self(['error' => $error], $status);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @param array<string, mixed> $options same keys as setcookie()'s options array */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    /** @return list<array{name: string, value: string, options: array<string, mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
        if ($this->status !== 204) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
    }
}
