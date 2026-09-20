<?php

declare(strict_types=1);

use SyncBridge\Support\Database;
use SyncBridge\Support\Env;
use SyncBridge\Support\Json;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $pdo = (new Database())->connection();
    $retentionDays = max(1, min(Env::int('DEMO_RETENTION_DAYS', 7), 30));
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $retentionDays . ' days')
        ->format('Y-m-d H:i:s.u');

    $pdo->beginTransaction();
    $targets = $pdo->prepare(
        'DELETE tr FROM target_records tr
         INNER JOIN sync_events se ON se.id = tr.source_event_id
         WHERE se.created_at < :cutoff'
    );
    $targets->execute(['cutoff' => $cutoff]);
    $audit = $pdo->prepare(
        'DELETE ae FROM audit_events ae
         INNER JOIN sync_events se ON se.id = ae.event_id
         WHERE se.created_at < :cutoff'
    );
    $audit->execute(['cutoff' => $cutoff]);
    $events = $pdo->prepare('DELETE FROM sync_events WHERE created_at < :cutoff');
    $events->execute(['cutoff' => $cutoff]);
    $limits = $pdo->prepare('DELETE FROM rate_limit_buckets WHERE expires_at < :now');
    $limits->execute(['now' => gmdate('Y-m-d H:i:s') . '.000000']);
    $pdo->commit();

    echo Json::encode([
        'status' => 'ok',
        'retention_days' => $retentionDays,
        'events_deleted' => $events->rowCount(),
        'rate_limit_buckets_deleted' => $limits->rowCount(),
    ]) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('SyncBridge cleanup failed: ' . $exception);
    fwrite(STDERR, "Cleanup failed. Check the server log.\n");
    exit(1);
}

