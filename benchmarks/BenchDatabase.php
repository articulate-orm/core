<?php

namespace Articulate\Benchmarks;

use Articulate\Connection;
use Articulate\Tests\ConnectionPool;

/**
 * MySQL-only fixture database for benchmarks and performance budgets.
 */
final class BenchDatabase {
    public readonly Connection $connection;

    public readonly QueryCounter $queries;

    public function __construct()
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        if ($connection === null) {
            throw new \RuntimeException('MySQL is not available; benchmarks need a MySQL connection.');
        }

        $this->connection = $connection;
        $this->queries = new QueryCounter();
        $this->connection->setQueryLogger($this->queries);
    }

    public function createAccounts(int $rows): void
    {
        $this->dropAccounts();
        $this->connection->executeQuery(
            'CREATE TABLE bench_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                bio TEXT NOT NULL,
                login_count INT NOT NULL DEFAULT 0
            )'
        );

        for ($offset = 0; $offset < $rows; $offset += 500) {
            $batch = min(500, $rows - $offset);
            $placeholders = implode(',', array_fill(0, $batch, '(?, ?, ?, 0)'));
            $values = [];
            for ($i = 0; $i < $batch; ++$i) {
                $n = $offset + $i;
                array_push($values, "user{$n}@example.com", "User {$n}", str_repeat('bio ', 20));
            }
            $this->connection->executeQuery(
                "INSERT INTO bench_accounts (email, display_name, bio, login_count) VALUES {$placeholders}",
                $values
            );
        }

        $this->queries->reset();
    }

    public function dropAccounts(): void
    {
        $this->connection->executeQuery('DROP TABLE IF EXISTS bench_accounts');
    }
}
