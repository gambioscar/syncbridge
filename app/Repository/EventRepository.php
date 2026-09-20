<?php

declare(strict_types=1);

namespace SyncBridge\Repository;

use PDO;
use PDOException;
use SyncBridge\Support\Json;
use SyncBridge\Support\Uuid;

final class EventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{uuid:string,status:string,duplicate:bool}
     */
    public function enqueue(array $payload, string $rawBody, ?string $demoSessionId): array
    {
        $idempotencyKey = (string) ($payload['event_id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? '');
        $now = gmdate('Y-m-d H:i:s.u');

        $this->pdo->beginTransaction();
        try {
            $existing = $this->findByIdempotencyKey($idempotencyKey, true);
            if ($existing !== null) {
                $statement = $this->pdo->prepare(
                    'UPDATE sync_events SET duplicate_count = duplicate_count + 1, updated_at = :updated_at WHERE id = :id'
                );
                $statement->execute(['updated_at' => $now, 'id' => $existing['id']]);
                $this->audit((int) $existing['id'], $demoSessionId, 'duplicate_received', 'api_client', null, null, [
                    'idempotency_key' => $idempotencyKey,
                ], $now);
                $this->pdo->commit();

                return ['uuid' => (string) $existing['uuid'], 'status' => (string) $existing['status'], 'duplicate' => true];
            }

            $uuid = Uuid::v4();
            $statement = $this->pdo->prepare(
                'INSERT INTO sync_events (
                    uuid, demo_session_id, idempotency_key, event_type, status,
                    payload_json, payload_sha256, attempts, max_attempts,
                    next_attempt_at, received_at, created_at, updated_at
                ) VALUES (
                    :uuid, :demo_session_id, :idempotency_key, :event_type, \'queued\',
                    :payload_json, :payload_sha256, 0, 5,
                    :next_attempt_at, :received_at, :created_at, :updated_at
                )'
            );
            $statement->execute([
                'uuid' => $uuid,
                'demo_session_id' => $demoSessionId,
                'idempotency_key' => $idempotencyKey,
                'event_type' => $eventType,
                'payload_json' => $rawBody,
                'payload_sha256' => hash('sha256', $rawBody),
                'next_attempt_at' => $now,
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $eventId = (int) $this->pdo->lastInsertId();
            $this->audit($eventId, $demoSessionId, 'webhook_accepted', 'api_client', null, 'queued', [
                'event_type' => $eventType,
                'payload_sha256' => hash('sha256', $rawBody),
            ], $now);
            $this->pdo->commit();

            return ['uuid' => $uuid, 'status' => 'queued', 'duplicate' => false];
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // A concurrent delivery can win the unique idempotency-key insert.
            if ($exception->getCode() === '23000') {
                $existing = $this->findByIdempotencyKey($idempotencyKey, false);
                if ($existing !== null) {
                    return ['uuid' => (string) $existing['uuid'], 'status' => (string) $existing['status'], 'duplicate' => true];
                }
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotencyKey(string $key, bool $forUpdate): ?array
    {
        $sql = 'SELECT id, uuid, status FROM sync_events WHERE idempotency_key = :key LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['key' => $key]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        ?int $eventId,
        ?string $sessionId,
        string $action,
        string $actorType,
        ?string $fromStatus,
        ?string $toStatus,
        array $metadata,
        string $createdAt
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events (
                event_id, demo_session_id, action, actor_type, from_status,
                to_status, metadata_json, created_at
            ) VALUES (
                :event_id, :demo_session_id, :action, :actor_type, :from_status,
                :to_status, :metadata_json, :created_at
            )'
        );
        $statement->execute([
            'event_id' => $eventId,
            'demo_session_id' => $sessionId,
            'action' => $action,
            'actor_type' => $actorType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata_json' => Json::encode($metadata),
            'created_at' => $createdAt,
        ]);
    }
}

