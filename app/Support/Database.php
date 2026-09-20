<?php

declare(strict_types=1);

namespace SyncBridge\Support;

use PDO;
use PDOException;

final class Database
{
    private ?PDO $connection = null;

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::int('DB_PORT', 3306);
        $database = Env::get('DB_DATABASE');
        $username = Env::get('DB_USERNAME');
        $password = Env::get('DB_PASSWORD');

        if ($database === '' || $username === '') {
            throw new \RuntimeException('Database configuration is incomplete.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        try {
            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            error_log('SyncBridge database connection failed: ' . $exception->getMessage());
            throw new \RuntimeException('Database unavailable. Check the configuration and try again.');
        }

        return $this->connection;
    }
}

