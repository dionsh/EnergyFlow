<?php

declare(strict_types=1);

use EnergyFlow\Core\HttpException;
use EnergyFlow\Core\Migrator;
use EnergyFlow\Core\Validator;
use EnergyFlow\Utils\Time;

test('validator returns only declared fields, trimmed and cast', function (): void {
    $clean = Validator::validate(
        ['email' => '  Me@Example.COM ', 'employees' => '28', 'extra' => 'ignored'],
        ['email' => ['required', 'email'], 'employees' => ['nullable', 'int', 'min:0']],
    );
    assertSame(['email' => 'me@example.com', 'employees' => 28], $clean);
});

test('validator reports a machine-readable code per field', function (): void {
    $e = assertThrows(HttpException::class, fn () => Validator::validate(
        ['password' => 'short', 'locale' => 'de', 'employees' => '-1'],
        [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:10'],
            'locale' => ['nullable', 'in:en,sq'],
            'employees' => ['nullable', 'int', 'min:0'],
        ],
    ));
    assertSame(422, $e->status);
    assertSame(['email' => 'required', 'password' => 'min_length:10', 'locale' => 'invalid_choice', 'employees' => 'min:0'], $e->fields);
});

test('validator omits absent optional fields (PATCH semantics) and keeps explicit nulls', function (): void {
    $clean = Validator::validate(['city' => ''], ['city' => ['nullable', 'string'], 'name' => ['string']]);
    assertSame(['city' => null], $clean);
});

test('validator checks dates and times strictly', function (): void {
    assertSame(['d' => '2026-11-15', 't' => '21:40'], Validator::validate(['d' => '2026-11-15', 't' => '21:40'], ['d' => ['date'], 't' => ['time']]));
    $e = assertThrows(HttpException::class, fn () => Validator::validate(['d' => '2026-02-30', 't' => '25:00'], ['d' => ['date'], 't' => ['time']]));
    assertSame(['d' => 'invalid_date', 't' => 'invalid_time'], $e->fields);
});

test('migration splitter ignores comments and empty statements', function (): void {
    $sql = "-- header; with a semicolon\nCREATE TABLE a (id INT);\n\n  -- note\nINSERT INTO a VALUES (1);\n";
    assertSame(['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'], Migrator::statements($sql));
});

test('database datetimes are exposed as ISO-8601 UTC', function (): void {
    assertSame('2026-11-15T21:40:00Z', Time::iso('2026-11-15 21:40:00'));
    assertSame(null, Time::iso(null));
});
