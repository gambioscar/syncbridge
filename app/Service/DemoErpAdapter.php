<?php

declare(strict_types=1);

namespace SyncBridge\Service;

use SyncBridge\Domain\PermanentSyncException;
use SyncBridge\Domain\RetryableSyncException;

final class DemoErpAdapter
{
    /**
     * @param array<string, mixed> $event
     * @return array{record_type:string,external_id:string,payload:array<string,mixed>,response:array<string,mixed>}
     */
    public function synchronize(array $event): array
    {
        $payload = json_decode((string) $event['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new PermanentSyncException('The stored event payload is invalid.');
        }

        $scenario = is_string($payload['scenario'] ?? null) ? $payload['scenario'] : 'success';
        $attempt = (int) $event['attempts'];
        if ($scenario === 'temporary_failure' && $attempt < 3) {
            throw new RetryableSyncException('The simulated ERP is temporarily unavailable.', 503);
        }
        if ($scenario === 'permanent_failure') {
            throw new PermanentSyncException('The ERP rejected the record: invalid demonstration data.', 422);
        }

        $eventType = (string) ($payload['event_type'] ?? '');
        $data = $payload['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new PermanentSyncException('The event data is missing or invalid.');
        }

        if ($eventType === 'order.created') {
            $externalId = $this->requiredString($data, 'order_id');
            $normalized = $this->normalizeOrder($data);
            $recordType = 'order';
        } elseif ($eventType === 'customer.updated') {
            $externalId = $this->requiredString($data, 'customer_id');
            $normalized = $this->normalizeCustomer($data);
            $recordType = 'customer';
        } else {
            throw new PermanentSyncException('The event type is not supported by the ERP adapter.');
        }

        return [
            'record_type' => $recordType,
            'external_id' => $externalId,
            'payload' => $normalized,
            'response' => [
                'status' => 'accepted',
                'erp_record_id' => strtoupper($recordType) . '-' . substr(hash('sha256', $externalId), 0, 10),
                'synchronized_at' => gmdate('c'),
            ],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeOrder(array $data): array
    {
        $currency = $this->requiredString($data, 'currency');
        $total = $data['total'] ?? null;
        if (!is_int($total) && !is_float($total)) {
            throw new PermanentSyncException('Order total must be numeric.');
        }

        return [
            'external_order_id' => $this->requiredString($data, 'order_id'),
            'external_customer_id' => $this->requiredString($data, 'customer_id'),
            'currency' => strtoupper($currency),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'subtotal' => (float) ($data['subtotal'] ?? 0),
            'tax' => (float) ($data['tax'] ?? 0),
            'total' => (float) $total,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeCustomer(array $data): array
    {
        $email = strtolower($this->requiredString($data, 'email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new PermanentSyncException('Customer email is invalid.');
        }

        return [
            'external_customer_id' => $this->requiredString($data, 'customer_id'),
            'full_name' => trim($this->requiredString($data, 'first_name') . ' ' . $this->requiredString($data, 'last_name')),
            'email' => $email,
            'country' => strtoupper($this->requiredString($data, 'country')),
            'marketing_consent' => (bool) ($data['marketing_consent'] ?? false),
        ];
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new PermanentSyncException(sprintf('%s is required.', $key));
        }

        return trim($value);
    }
}

