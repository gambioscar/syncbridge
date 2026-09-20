<?php

declare(strict_types=1);

namespace SyncBridge\Support;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function consume(string $scope, int $limit, int $windowSeconds): bool
    {
        $secret = Env::get('APP_KEY');
        if (strlen($secret) < 24) {
            throw new \RuntimeException('APP_KEY must contain at least 24 characters.');
        }

        $address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $bucketKey = hash_hmac('sha256', $scope . '|' . $address, $secret);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s.u');
        $expiresSql = $now->modify('+' . max(1, $windowSeconds) . ' seconds')->format('Y-m-d H:i:s.u');

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT request_count, expires_at FROM rate_limit_buckets WHERE bucket_key = :bucket_key FOR UPDATE'
            );
            $statement->execute(['bucket_key' => $bucketKey]);
            $bucket = $statement->fetch();

            if (!is_array($bucket)) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rate_limit_buckets (bucket_key, window_started_at, request_count, expires_at)
                     VALUES (:bucket_key, :started_at, 1, :expires_at)'
                );
                $insert->execute(['bucket_key' => $bucketKey, 'started_at' => $nowSql, 'expires_at' => $expiresSql]);
                $this->pdo->commit();
                return true;
            }

            if ((string) $bucket['expires_at'] <= $nowSql) {
                $reset = $this->pdo->prepare(
                    'UPDATE rate_limit_buckets SET window_started_at = :started_at,
                     request_count = 1, expires_at = :expires_at WHERE bucket_key = :bucket_key'
                );
                $reset->execute(['started_at' => $nowSql, 'expires_at' => $expiresSql, 'bucket_key' => $bucketKey]);
                $this->pdo->commit();
                return true;
            }

            if ((int) $bucket['request_count'] >= $limit) {
                $this->pdo->commit();
                return false;
            }

            $increment = $this->pdo->prepare(
                'UPDATE rate_limit_buckets SET request_count = request_count + 1 WHERE bucket_key = :bucket_key'
            );
            $increment->execute(['bucket_key' => $bucketKey]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

