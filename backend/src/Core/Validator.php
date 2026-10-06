<?php

declare(strict_types=1);

namespace EnergyFlow\Core;

/**
 * Declarative input validation. Returns only the declared fields, trimmed and
 * cast; throws a 422 with machine-readable error codes per field otherwise.
 *
 *   Validator::validate($input, [
 *       'email'     => ['required', 'email', 'max:190'],
 *       'employees' => ['nullable', 'int', 'min:0', 'max:100000'],
 *       'locale'    => ['nullable', 'in:en,sq'],
 *   ]);
 *
 * Fields that are absent and not required are omitted from the result, which
 * makes PATCH semantics ("only update what was sent") straightforward.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $present = array_key_exists($field, $input);
            $value = $present ? $input[$field] : null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $isEmpty = $value === null || $value === '';

            if ($isEmpty) {
                if (in_array('required', $fieldRules, true)) {
                    $errors[$field] = 'required';
                } elseif ($present && in_array('nullable', $fieldRules, true)) {
                    $clean[$field] = null;
                }
                continue;
            }

            $error = self::check($value, $fieldRules);
            if ($error !== null) {
                $errors[$field] = $error;
                continue;
            }
            $clean[$field] = self::cast($value, $fieldRules);
        }

        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        return $clean;
    }

    private static function check(mixed $value, array $rules): ?string
    {
        $numeric = in_array('int', $rules, true) || in_array('number', $rules, true);

        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $failed = match ($name) {
                'required', 'nullable' => false,
                'string' => !is_string($value),
                'email' => !is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false,
                'int' => filter_var($value, FILTER_VALIDATE_INT) === false,
                'number' => !is_numeric($value),
                'bool' => !is_bool($value) && !in_array($value, [0, 1, '0', '1'], true),
                'in' => !in_array((string) $value, explode(',', (string) $arg), true),
                'min' => $numeric ? $value < (float) $arg : mb_strlen((string) $value) < (int) $arg,
                'max' => $numeric ? $value > (float) $arg : mb_strlen((string) $value) > (int) $arg,
                'date' => !self::isDate((string) $value, 'Y-m-d'),
                'time' => !self::isDate((string) $value, 'H:i'),
                'regex' => !preg_match((string) $arg, (string) $value),
                default => throw new \LogicException("Unknown validation rule '{$name}'"),
            };
            if ($failed) {
                return match ($name) {
                    'min', 'max' => ($numeric ? $name : "{$name}_length") . ':' . $arg,
                    'in' => 'invalid_choice',
                    default => 'invalid_' . $name,
                };
            }
        }
        return null;
    }

    private static function cast(mixed $value, array $rules): mixed
    {
        return match (true) {
            in_array('int', $rules, true) => (int) $value,
            in_array('number', $rules, true) => (float) $value,
            in_array('bool', $rules, true) => (bool) $value,
            in_array('email', $rules, true) => mb_strtolower((string) $value),
            default => $value,
        };
    }

    private static function isDate(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $date !== false && $date->format($format) === $value;
    }
}
