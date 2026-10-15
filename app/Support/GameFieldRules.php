<?php

namespace App\Support;

/**
 * Admins type Laravel validation rules for game fields (for example "digits_between:5,15").
 * Those rules run against customer input later, so only a safe whitelist is accepted:
 * no exists/unique/regex/file/closure-style rules.
 */
final class GameFieldRules
{
    private const ALLOWED = [
        'required', 'nullable', 'string', 'numeric', 'integer',
        'digits', 'digits_between', 'alpha', 'alpha_num', 'alpha_dash',
        'min', 'max', 'between', 'size', 'in',
    ];

    public static function isValid(?string $rules): bool
    {
        if ($rules === null || trim($rules) === '') {
            return true;
        }

        if (strlen($rules) > 255) {
            return false;
        }

        foreach (explode('|', $rules) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                return false;
            }

            [$name, $params] = array_pad(explode(':', $rule, 2), 2, null);

            if (! in_array($name, self::ALLOWED, true)) {
                return false;
            }

            if ($params !== null && ! preg_match('/^[A-Za-z0-9_\-, ]+$/', $params)) {
                return false;
            }
        }

        return true;
    }
}