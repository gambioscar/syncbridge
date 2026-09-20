<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = []): never
    {
        $templatePath = SYNCBRIDGE_ROOT . '/resources/views/' . $template . '.php';
        if (!is_file($templatePath)) {
            throw new \RuntimeException('View not found.');
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = (string) ob_get_clean();

        require SYNCBRIDGE_ROOT . '/resources/views/layout.php';
        exit;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(string $value): string
    {
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'In coda',
            'processing' => 'In elaborazione',
            'succeeded' => 'Sincronizzato',
            'retrying' => 'In attesa di retry',
            'failed' => 'Errore permanente',
            'dead_letter' => 'Dead letter',
            default => $status,
        };
    }

    public static function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            'order.created' => 'Nuovo ordine',
            'customer.updated' => 'Cliente aggiornato',
            default => $eventType,
        };
    }

    public static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/Rome'))
                ->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $value;
        }
    }
}

