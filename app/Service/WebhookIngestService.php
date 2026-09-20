<?php

declare(strict_types=1);

namespace SyncBridge\Service;

use SyncBridge\Repository\EventRepository;
use SyncBridge\Security\WebhookSigner;
use SyncBridge\Support\Json;
use SyncBridge\Support\Uuid;

final class WebhookIngestService
{
    public function __construct(
        private readonly WebhookSigner $signer,
        private readonly EventRepository $events
    ) {
    }

    /** @return array{uuid:string,status:string,duplicate:bool} */
    public function ingest(
        string $rawBody,
        string $timestamp,
        string $signature,
        ?string $demoSessionId
    ): array {
        if (strlen($rawBody) > 262144) {
            throw new \LengthException('Webhook payload is too large.');
        }
        if (!$this->signer->verify($rawBody, $timestamp, $signature)) {
            throw new \UnexpectedValueException('Webhook signature is invalid or expired.');
        }

        $payload = Json::decodeObject($rawBody);
        $eventId = $payload['event_id'] ?? null;
        $eventType = $payload['event_type'] ?? null;
        $data = $payload['data'] ?? null;

        if (!is_string($eventId) || !Uuid::isValid($eventId)) {
            throw new \InvalidArgumentException('event_id must be a valid UUID v4.');
        }
        if (!is_string($eventType) || !in_array($eventType, ['order.created', 'customer.updated'], true)) {
            throw new \InvalidArgumentException('event_type is not supported.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('data must be a JSON object.');
        }
        if ($demoSessionId !== null && !Uuid::isValid($demoSessionId)) {
            $demoSessionId = null;
        }

        return $this->events->enqueue($payload, $rawBody, $demoSessionId);
    }
}

