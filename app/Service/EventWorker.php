<?php

declare(strict_types=1);

namespace SyncBridge\Service;

use SyncBridge\Domain\PermanentSyncException;
use SyncBridge\Domain\RetryableSyncException;
use SyncBridge\Repository\WorkerRepository;

final class EventWorker
{
    private const RETRY_DELAYS = [60, 300, 900, 3600];

    public function __construct(
        private readonly WorkerRepository $events,
        private readonly DemoErpAdapter $target
    ) {
    }

    /** @return array{processed:int,succeeded:int,retrying:int,failed:int,dead_letter:int} */
    public function run(int $limit, ?string $workerId = null, ?string $sessionId = null): array
    {
        $limit = max(1, min($limit, 50));
        $workerId ??= 'worker-' . bin2hex(random_bytes(5));
        $summary = ['processed' => 0, 'succeeded' => 0, 'retrying' => 0, 'failed' => 0, 'dead_letter' => 0];

        for ($i = 0; $i < $limit; $i++) {
            $event = $this->events->claimNext($workerId, $sessionId);
            if ($event === null) {
                break;
            }

            $summary['processed']++;
            $startedAt = hrtime(true);
            try {
                $result = $this->target->synchronize($event);
                $duration = $this->durationMs($startedAt);
                $this->events->markSucceeded($event, $result, $duration);
                $summary['succeeded']++;
            } catch (RetryableSyncException $exception) {
                $duration = $this->durationMs($startedAt);
                $delayIndex = min(max(((int) $event['attempts']) - 1, 0), count(self::RETRY_DELAYS) - 1);
                $status = $this->events->markRetryableFailure(
                    $event,
                    $exception->getMessage(),
                    $exception->responseCode(),
                    $duration,
                    self::RETRY_DELAYS[$delayIndex]
                );
                $summary[$status]++;
            } catch (PermanentSyncException | \JsonException $exception) {
                $duration = $this->durationMs($startedAt);
                $responseCode = $exception instanceof PermanentSyncException ? $exception->responseCode() : 422;
                $this->events->markPermanentFailure($event, $exception->getMessage(), $responseCode, $duration);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
