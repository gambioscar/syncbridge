<?php

declare(strict_types=1);

namespace SyncBridge\Repository;

use PDO;
use SyncBridge\Support\Json;

final class EventQueryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function counts(string $sessionId): array
    {
        $counts = [
            'total' => 0,
            'queued' => 0,
            'processing' => 0,
            'succeeded' => 0,
            'retrying' => 0,
            'failed' => 0,
            'dead_letter' => 0,
        ];
        $statement = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS aggregate FROM sync_events
             WHERE demo_session_id = :session_id GROUP BY status'
        );
        $statement->execute(['session_id' => $sessionId]);
        foreach ($statement->fetchAll() as $row) {
            $status = (string) $row['status'];
            $value = (int) $row['aggregate'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $value;
                $counts['total'] += $value;
            }
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public function recent(string $sessionId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $statement = $this->pdo->prepare(
            'SELECT uuid, event_type, status, attempts, max_attempts,
                    duplicate_count, last_error_message, next_attempt_at, created_at, updated_at
             FROM sync_events WHERE demo_session_id = :session_id
             ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute(['session_id' => $sessionId]);
        return $statement->fetchAll();
    }

    /** @return array{event:array<string,mixed>,attempts:list<array<string,mixed>>,audit:list<array<string,mixed>>}|null */
    public function detail(string $uuid, string $sessionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM sync_events WHERE uuid = :uuid AND demo_session_id = :session_id LIMIT 1'
        );
        $statement->execute(['uuid' => $uuid, 'session_id' => $sessionId]);
        $event = $statement->fetch();
        if (!is_array($event)) {
            return null;
        }

        $attempts = $this->pdo->prepare(
            'SELECT attempt_number, outcome, response_code, response_json,
                    error_code, error_message, duration_ms, started_at, finished_at
             FROM sync_attempts WHERE event_id = :event_id ORDER BY attempt_number DESC'
        );
        $attempts->execute(['event_id' => $event['id']]);

        $audit = $this->pdo->prepare(
            'SELECT action, actor_type, from_status, to_status, metadata_json, created_at
             FROM audit_events WHERE event_id = :event_id ORDER BY id DESC'
        );
        $audit->execute(['event_id' => $event['id']]);

        return [
            'event' => $event,
            'attempts' => $attempts->fetchAll(),
            'audit' => $audit->fetchAll(),
        ];
    }

    public function retryNow(string $uuid, string $sessionId): bool
    {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $event = $this->lockEvent($uuid, $sessionId);
            if ($event === null || (string) $event['status'] !== 'retrying') {
                $this->pdo->rollBack();
                return false;
            }
            $statement = $this->pdo->prepare(
                'UPDATE sync_events
                 SET next_attempt_at = :next_attempt_at, updated_at = :updated_at
                 WHERE id = :id'
            );
            $statement->execute([
                'next_attempt_at' => $now,
                'updated_at' => $now,
                'id' => $event['id'],
            ]);
            $this->audit($event, 'retry_requested', 'retrying', 'retrying', ['manual' => true], $now);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function replay(string $uuid, string $sessionId): bool
    {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $event = $this->lockEvent($uuid, $sessionId);
            if ($event === null || !in_array((string) $event['status'], ['failed', 'dead_letter'], true)) {
                $this->pdo->rollBack();
                return false;
            }
            $oldStatus = (string) $event['status'];
            $newMax = max((int) $event['max_attempts'], (int) $event['attempts'] + 5);
            $statement = $this->pdo->prepare(
                "UPDATE sync_events SET status = 'queued', max_attempts = :max_attempts,
                 next_attempt_at = :next_attempt_at, completed_at = NULL,
                 last_error_code = NULL, last_error_message = NULL,
                 locked_at = NULL, locked_by = NULL, updated_at = :updated_at
                 WHERE id = :id"
            );
            $statement->execute([
                'max_attempts' => $newMax,
                'next_attempt_at' => $now,
                'updated_at' => $now,
                'id' => $event['id'],
            ]);
            $this->audit($event, 'event_replayed', $oldStatus, 'queued', ['manual' => true], $now);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function lockEvent(string $uuid, string $sessionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, demo_session_id, status, attempts, max_attempts
             FROM sync_events WHERE uuid = :uuid AND demo_session_id = :session_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['uuid' => $uuid, 'session_id' => $sessionId]);
        $event = $statement->fetch();
        return is_array($event) ? $event : null;
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $metadata */
    private function audit(array $event, string $action, string $fromStatus, string $toStatus, array $metadata, string $createdAt): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO audit_events (
                event_id, demo_session_id, action, actor_type, from_status,
                to_status, metadata_json, created_at
            ) VALUES (
                :event_id, :session_id, :action, 'visitor', :from_status,
                :to_status, :metadata_json, :created_at
            )"
        );
        $statement->execute([
            'event_id' => $event['id'],
            'session_id' => $event['demo_session_id'],
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata_json' => Json::encode($metadata),
            'created_at' => $createdAt,
        ]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
