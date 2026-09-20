<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read environment configuration.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (is_string($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        return self::$values[$key] ?? $default ?? '';
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value !== '' && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : $default;
    }
}

