<?php

declare(strict_types=1);

use SyncBridge\Repository\WorkerRepository;
use SyncBridge\Service\DemoErpAdapter;
use SyncBridge\Service\EventWorker;
use SyncBridge\Support\Database;
use SyncBridge\Support\Env;
use SyncBridge\Support\Json;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $worker = new EventWorker(
        new WorkerRepository((new Database())->connection()),
        new DemoErpAdapter()
    );
    $summary = $worker->run(Env::int('WORKER_BATCH_SIZE', 10));
    echo Json::encode($summary) . PHP_EOL;
    exit(0);
} catch (\Throwable $exception) {
    error_log('SyncBridge CLI worker failed: ' . $exception);
    fwrite(STDERR, "Worker failed. Check the server log.\n");
    exit(1);
}

