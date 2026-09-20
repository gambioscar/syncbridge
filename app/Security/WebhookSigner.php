<?php

declare(strict_types=1);

namespace SyncBridge\Security;

final class WebhookSigner
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300
    ) {
        if (strlen($this->secret) < 24) {
            throw new \InvalidArgumentException('Webhook secret must contain at least 24 characters.');
        }
    }

    public function sign(string $rawBody, int $timestamp): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
    }

    public function verify(string $rawBody, string $timestamp, string $signature, ?int $now = null): bool
    {
        if (!ctype_digit($timestamp) || !preg_match('/^sha256=[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        $timestampValue = (int) $timestamp;
        $now ??= time();
        if (abs($now - $timestampValue) > $this->toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($rawBody, $timestampValue), strtolower($signature));
    }
}

