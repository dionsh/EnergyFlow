<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

final class Request
{
    private const MAX_BODY_BYTES = 1_048_576;

    /** @var array<string, mixed> values attached by middleware (user, company_id…) */
    private array $attributes = [];
    private ?array $json = null;

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers lower-cased names
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        private readonly string $body = '',
        public readonly string $ip = '0.0.0.0',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: rtrim($path, '/') ?: '/',
            query: array_map('strval', array_filter($_GET, 'is_scalar')),
            headers: $headers,
            cookies: array_map('strval', array_filter($_COOKIE, 'is_scalar')),
            body: (string) file_get_contents('php://input', length: self::MAX_BODY_BYTES + 1),
            ip: self::clientIp($headers),
        );
    }

    /** Exact request body (needed to verify device signatures). */
    public function rawBody(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (strlen($this->body) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'payload_too_large', 'The request body is too large.');
        }
        if (trim($this->body) === '') {
            return $this->json = [];
        }
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw HttpException::badRequest('invalid_json', 'The request body must be a JSON object.');
        }
        return $this->json = $decoded;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** The signed-in user (set by the Authenticate middleware). */
    public function user(): array
    {
        return $this->attributes['user'] ?? throw HttpException::unauthorized();
    }

    /** The tenant of the signed-in user. Never taken from client input. */
    public function companyId(): int
    {
        return (int) $this->user()['company_id'];
    }

    public function isUnsafeMethod(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Best-effort client IP. Used only for rate-limit keys, never for authorization:
     * behind Render's proxy REMOTE_ADDR is the proxy, and forwarding headers can be forged.
     */
    private static function clientIp(array $headers): string
    {
        $forwarded = $headers['x-forwarded-for'] ?? '';
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
