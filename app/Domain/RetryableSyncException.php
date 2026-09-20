<?php

declare(strict_types=1);

namespace SyncBridge\Domain;

final class RetryableSyncException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $responseCode = 503)
    {
        parent::__construct($message);
    }

    public function responseCode(): int
    {
        return $this->responseCode;
    }
}

