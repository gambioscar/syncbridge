<?php

declare(strict_types=1);

namespace SyncBridge\Repository;

use PDO;
use SyncBridge\Support\Json;

final class WorkerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function claimNext(string $workerId, ?string $sessionId = null): ?array
    {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $sql = "SELECT * FROM sync_events
                    WHERE status IN ('queued','retrying')
                      AND (next_attempt_at IS NULL OR next_attempt_at <= :now)";
            $parameters = ['now' => $now];
            if ($sessionId !== null) {
                $sql .= ' AND demo_session_id = :session_id';
                $parameters['session_id'] = $sessionId;
            }
            $sql .= ' ORDER BY next_attempt_at ASC, id ASC LIMIT 1 FOR UPDATE';
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            $event = $statement->fetch();
            if (!is_array($event)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE sync_events
                 SET status = 'processing', attempts = attempts + 1,
                     locked_at = :locked_at, locked_by = :locked_by,
                     processing_started_at = :started_at, updated_at = :updated_at
                 WHERE id = :id"
            );
            $update->execute([
                'locked_at' => $now,
                'locked_by' => $workerId,
                'started_at' => $now,
                'updated_at' => $now,
                'id' => $event['id'],
            ]);
            $this->audit(
                (int) $event['id'],
                is_string($event['demo_session_id']) ? $event['demo_session_id'] : null,
                'processing_started',
                'worker',
                (string) $event['status'],
                'processing',
                ['worker_id' => $workerId, 'attempt' => ((int) $event['attempts']) + 1],
                $now
            );
            $this->pdo->commit();

            $event['status'] = 'processing';
            $event['attempts'] = ((int) $event['attempts']) + 1;
            $event['locked_by'] = $workerId;
            return $event;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param array{record_type:string,external_id:string,payload:array<string,mixed>,response:array<string,mixed>} $result
     */
    public function markSucceeded(array $event, array $result, int $durationMs): void
    {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare(
                'INSERT INTO target_records (
                    record_type, external_id, source_event_id, payload_json,
                    synchronized_at, created_at, updated_at
                ) VALUES (
                    :record_type, :external_id, :source_event_id, :payload_json,
                    :synchronized_at, :created_at, :updated_at
                ) ON DUPLICATE KEY UPDATE
                    source_event_id = VALUES(source_event_id),
                    payload_json = VALUES(payload_json),
                    synchronized_at = VALUES(synchronized_at),
                    updated_at = VALUES(updated_at)'
            );
            $target->execute([
                'record_type' => $result['record_type'],
                'external_id' => $result['external_id'],
                'source_event_id' => $event['id'],
                'payload_json' => Json::encode($result['payload']),
                'synchronized_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->insertAttempt($event, 'succeeded', 200, $result['response'], null, null, $durationMs, $now);
            $update = $this->pdo->prepare(
                "UPDATE sync_events
                 SET status = 'succeeded', normalized_payload_json = :normalized,
                     next_attempt_at = NULL, locked_at = NULL, locked_by = NULL,
                     last_error_code = NULL, last_error_message = NULL,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'processing'"
            );
            $update->execute([
                'normalized' => Json::encode($result['payload']),
                'completed_at' => $now,
                'updated_at' => $now,
                'id' => $event['id'],
            ]);
            $this->audit((int) $event['id'], $this->sessionId($event), 'synchronization_succeeded', 'worker', 'processing', 'succeeded', [
                'attempt' => (int) $event['attempts'],
                'duration_ms' => $durationMs,
                'target_record' => $result['external_id'],
            ], $now);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $event */
    public function markRetryableFailure(array $event, string $message, int $responseCode, int $durationMs, int $delaySeconds): string
    {
        $attempt = (int) $event['attempts'];
        $maxAttempts = (int) $event['max_attempts'];
        $deadLetter = $attempt >= $maxAttempts;
        $status = $deadLetter ? 'dead_letter' : 'retrying';
        $now = $this->now();
        $nextAttemptAt = $deadLetter ? null : gmdate('Y-m-d H:i:s', time() + $delaySeconds) . '.000000';

        $this->pdo->beginTransaction();
        try {
            $this->insertAttempt($event, 'retryable_error', $responseCode, null, 'temporary_target_error', $message, $durationMs, $now);
            $statement = $this->pdo->prepare(
                "UPDATE sync_events
                 SET status = :status, next_attempt_at = :next_attempt_at,
                     locked_at = NULL, locked_by = NULL,
                     last_error_code = 'temporary_target_error',
                     last_error_message = :message,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'processing'"
            );
            $statement->execute([
                'status' => $status,
                'next_attempt_at' => $nextAttemptAt,
                'message' => $message,
                'completed_at' => $deadLetter ? $now : null,
                'updated_at' => $now,
                'id' => $event['id'],
            ]);
            $this->audit((int) $event['id'], $this->sessionId($event), $deadLetter ? 'moved_to_dead_letter' : 'retry_scheduled', 'worker', 'processing', $status, [
                'attempt' => $attempt,
                'next_attempt_at' => $nextAttemptAt,
                'response_code' => $responseCode,
            ], $now);
            $this->pdo->commit();
            return $status;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $event */
    public function markPermanentFailure(array $event, string $message, int $responseCode, int $durationMs): void
    {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $this->insertAttempt($event, 'permanent_error', $responseCode, null, 'permanent_target_error', $message, $durationMs, $now);
            $statement = $this->pdo->prepare(
                "UPDATE sync_events
                 SET status = 'failed', next_attempt_at = NULL,
                     locked_at = NULL, locked_by = NULL,
                     last_error_code = 'permanent_target_error',
                     last_error_message = :message,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'processing'"
            );
            $statement->execute([
                'message' => $message,
                'completed_at' => $now,
                'updated_at' => $now,
                'id' => $event['id'],
            ]);
            $this->audit((int) $event['id'], $this->sessionId($event), 'synchronization_failed', 'worker', 'processing', 'failed', [
                'attempt' => (int) $event['attempts'],
                'response_code' => $responseCode,
            ], $now);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $event @param array<string, mixed>|null $response */
    private function insertAttempt(
        array $event,
        string $outcome,
        ?int $responseCode,
        ?array $response,
        ?string $errorCode,
        ?string $errorMessage,
        int $durationMs,
        string $finishedAt
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO sync_attempts (
                event_id, attempt_number, outcome, request_json, response_code,
                response_json, error_code, error_message, duration_ms,
                started_at, finished_at, created_at
            ) VALUES (
                :event_id, :attempt_number, :outcome, :request_json, :response_code,
                :response_json, :error_code, :error_message, :duration_ms,
                :started_at, :finished_at, :created_at
            )'
        );
        $statement->execute([
            'event_id' => $event['id'],
            'attempt_number' => $event['attempts'],
            'outcome' => $outcome,
            'request_json' => $event['payload_json'],
            'response_code' => $responseCode,
            'response_json' => $response === null ? null : Json::encode($response),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'started_at' => $event['processing_started_at'] ?? $finishedAt,
            'finished_at' => $finishedAt,
            'created_at' => $finishedAt,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(?int $eventId, ?string $sessionId, string $action, string $actorType, ?string $fromStatus, ?string $toStatus, array $metadata, string $createdAt): void
    {
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

    /** @param array<string, mixed> $event */
    private function sessionId(array $event): ?string
    {
        return isset($event['demo_session_id']) && is_string($event['demo_session_id'])
            ? $event['demo_session_id']
            : null;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
