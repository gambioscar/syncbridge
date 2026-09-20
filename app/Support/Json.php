<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class Json
{
    /** @param mixed $value */
    public static function encode($value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** @return array<string, mixed> */
    public static function decodeObject(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('The JSON body must be an object.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    public static function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo self::encode($payload);
        exit;
    }
}

