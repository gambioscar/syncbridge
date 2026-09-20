<?php

declare(strict_types=1);

namespace SyncBridge\Domain;

final class PermanentSyncException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $responseCode = 422)
    {
        parent::__construct($message);
    }

    public function responseCode(): int
    {
        return $this->responseCode;
    }
}

