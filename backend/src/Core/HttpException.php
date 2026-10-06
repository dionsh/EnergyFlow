<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

use RuntimeException;

/**
 * Thrown anywhere in a request to end it with a specific HTTP error.
 * `errorCode` is stable and machine-readable; the frontend translates it.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $fields field => error code */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message = '',
        public readonly array $fields = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public static function badRequest(string $code, string $message = ''): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthorized(string $message = 'Please sign in.'): self
    {
        return new self(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do this.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $code = 'not_found', string $message = 'Not found.'): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message = ''): self
    {
        return new self(409, $code, $message);
    }

    /** @param array<string, string> $fields */
    public static function validation(array $fields): self
    {
        return new self(422, 'validation_failed', 'Some fields are invalid.', $fields);
    }

    public static function tooManyRequests(string $message = 'Too many attempts. Please wait a moment.'): self
    {
        return new self(429, 'rate_limited', $message);
    }
}
