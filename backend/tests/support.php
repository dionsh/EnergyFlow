<?php

declare(strict_types=1);

use EnergyFlow\Core\Kernel;
use EnergyFlow\Core\Request;

/** A tiny test runner: no Composer, no PHPUnit, same Kernel as production. */
final class Tests
{
    /** @var list<array{name: string, fn: callable}> */
    private static array $tests = [];

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function run(): int
    {
        $failed = 0;
        foreach (self::$tests as $test) {
            try {
                ($test['fn'])();
                echo "  \u{2713} {$test['name']}" . PHP_EOL;
            } catch (Throwable $e) {
                $failed++;
                echo "  \u{2717} {$test['name']}" . PHP_EOL . "      " . $e->getMessage() . PHP_EOL;
                if (!$e instanceof AssertionFailed) {
                    echo '      at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
                }
            }
        }
        $total = count(self::$tests);
        self::$tests = [];
        echo ($failed === 0 ? "  {$total} passed" : "  {$failed} of {$total} FAILED") . PHP_EOL;
        return $failed === 0 ? 0 : 1;
    }
}

final class AssertionFailed extends RuntimeException
{
}

function test(string $name, callable $fn): void
{
    Tests::add($name, $fn);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? "{$message}: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = 'expected condition to be true'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

/** @param callable(): void $fn */
function assertThrows(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("expected {$class}, got " . $e::class . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("expected {$class} to be thrown");
}

/** Simulates a browser: keeps cookies between calls, sends the CSRF header. */
final class Client
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(private readonly string $ip = '10.0.0.1')
    {
    }

    /** @return array{status: int, body: mixed} */
    public function call(string $method, string $path, ?array $json = null, array $headers = ['x-ef-client' => 'web']): array
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = new Request(
            method: $method,
            path: '/api/v1' . parse_url($path, PHP_URL_PATH),
            query: array_map('strval', $query),
            headers: $headers + ['content-type' => 'application/json'],
            cookies: $this->cookies,
            body: $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE),
            ip: $this->ip,
        );
        $response = Kernel::handle($request);
        foreach ($response->cookies() as $cookie) {
            if ($cookie['value'] === '' || ($cookie['options']['expires'] ?? PHP_INT_MAX) < time()) {
                unset($this->cookies[$cookie['name']]);
            } else {
                $this->cookies[$cookie['name']] = $cookie['value'];
            }
        }
        return ['status' => $response->status, 'body' => $response->body];
    }

    public function get(string $path): array
    {
        return $this->call('GET', $path);
    }

    public function post(string $path, array $json = [], array $headers = ['x-ef-client' => 'web']): array
    {
        return $this->call('POST', $path, $json, $headers);
    }

    public function patch(string $path, array $json = []): array
    {
        return $this->call('PATCH', $path, $json);
    }
}

/** Registers a fresh company + owner and returns the signed-in client. */
function registeredOwner(string $email, string $company = 'Test SME'): Client
{
    $client = new Client('10.0.' . random_int(1, 250) . '.' . random_int(1, 250));
    $result = $client->post('/auth/register', [
        'full_name' => 'Owner ' . $company,
        'email' => $email,
        'password' => 'correct-horse-battery',
        'company_name' => $company,
        'city' => 'Prishtinë',
        'locale' => 'sq',
    ]);
    assertSame(201, $result['status'], 'register ' . json_encode($result['body']));
    return $client;
}
