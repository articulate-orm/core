<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Connection;
use Articulate\Exceptions\TransactionRequiredException;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LockTest extends DatabaseTestCase {
    private QueryBuilder $qb;

    private Connection $connection;

    #[DataProvider('databaseProvider')]
    public function testLockAddsForUpdateToSql(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->where('id = ?', 1)
            ->lock()
            ->getSQL();

        $this->assertEquals('SELECT id, name FROM users WHERE id = ? FOR UPDATE', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testLockThrowsExceptionWhenNoTransactionInGetResult(string $databaseName): void
    {
        $hostEnv = $databaseName === 'mysql' ? 'DATABASE_HOST' : 'DATABASE_HOST_PGSQL';
        $dsn = $databaseName === 'mysql'
            ? 'mysql:host=' . getenv($hostEnv) . ';dbname=' . getenv('DATABASE_NAME') . ';charset=utf8mb4'
            : 'pgsql:host=' . getenv($hostEnv) . ';port=5432;dbname=' . getenv('DATABASE_NAME');

        $connection = new Connection($dsn, getenv('DATABASE_USER'), getenv('DATABASE_PASSWORD'));

        $connection->executeQuery('DROP TABLE IF EXISTS test_users_lock');
        $connection->executeQuery('CREATE TABLE test_users_lock (id INT, name VARCHAR(255))');
        $connection->executeQuery('INSERT INTO test_users_lock (id, name) VALUES (1, \'John\')');

        $this->assertFalse($connection->inTransaction(), 'Connection should not be in a transaction without beginTransaction()');

        $this->expectException(TransactionRequiredException::class);
        $this->expectExceptionMessage('lock() requires an active transaction');

        (new QueryBuilder($connection))
            ->select('*')
            ->from('test_users_lock')
            ->where('id = ?', 1)
            ->lock()
            ->getResult();
    }

    #[DataProvider('databaseProvider')]
    public function testLockThrowsExceptionWhenNoTransactionInExecute(string $databaseName): void
    {
        $hostEnv = $databaseName === 'mysql' ? 'DATABASE_HOST' : 'DATABASE_HOST_PGSQL';
        $dsn = $databaseName === 'mysql'
            ? 'mysql:host=' . getenv($hostEnv) . ';dbname=' . getenv('DATABASE_NAME') . ';charset=utf8mb4'
            : 'pgsql:host=' . getenv($hostEnv) . ';port=5432;dbname=' . getenv('DATABASE_NAME');

        $connection = new Connection($dsn, getenv('DATABASE_USER'), getenv('DATABASE_PASSWORD'));

        $connection->executeQuery('DROP TABLE IF EXISTS test_users_lock_exec');
        $connection->executeQuery('CREATE TABLE test_users_lock_exec (id INT, name VARCHAR(255))');
        $connection->executeQuery('INSERT INTO test_users_lock_exec (id, name) VALUES (1, \'John\')');

        $this->assertFalse($connection->inTransaction(), 'Connection should not be in a transaction without beginTransaction()');

        $this->expectException(TransactionRequiredException::class);
        $this->expectExceptionMessage('lock() requires an active transaction');

        (new QueryBuilder($connection))
            ->from('test_users_lock_exec')
            ->where('id = ?', 1)
            ->lock()
            ->execute();
    }

    #[DataProvider('databaseProvider')]
    public function testLockWorksWithTransactionInGetResult(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $this->connection->executeQuery('DROP TABLE IF EXISTS test_users');
        $this->connection->executeQuery('CREATE TABLE test_users (id INT, name VARCHAR(255))');
        $this->connection->executeQuery('INSERT INTO test_users (id, name) VALUES (1, \'John\'), (2, \'Jane\')');

        $this->connection->beginTransaction();

        try {
            $result = $this->qb
                ->select('*')
                ->from('test_users')
                ->where('id = ?', 1)
                ->lock()
                ->getResult();

            $this->assertEquals([['id' => 1, 'name' => 'John']], $result);
        } finally {
            $this->connection->rollbackTransaction();
        }
    }

    #[DataProvider('databaseProvider')]
    public function testLockWorksWithTransactionInExecute(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $this->connection->executeQuery('DROP TABLE IF EXISTS test_users');
        $this->connection->executeQuery('CREATE TABLE test_users (id INT, name VARCHAR(255))');
        $this->connection->executeQuery('INSERT INTO test_users (id, name) VALUES (1, \'John\'), (2, \'Jane\')');

        $this->connection->beginTransaction();

        try {
            $rowCount = $this->qb
                ->from('test_users')
                ->where('id = ?', 1)
                ->lock()
                ->execute();

            $this->assertEquals(1, $rowCount);
        } finally {
            $this->connection->rollbackTransaction();
        }
    }

    #[DataProvider('databaseProvider')]
    public function testLockWithComplexQuery(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('u.id', 'u.name')
            ->from('users', 'u')
            ->join('posts p', 'p.user_id = u.id')
            ->where('u.active = ?', true)
            ->orderBy('u.name')
            ->limit(10)
            ->lock()
            ->getSQL();

        $expected = 'SELECT u.id, u.name FROM users u JOIN posts p ON p.user_id = u.id WHERE u.active = ? ORDER BY u.name ASC LIMIT 10 FOR UPDATE';
        $this->assertEquals($expected, $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testLockIsResetByResetMethod(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('id')
            ->from('users')
            ->lock();

        $sqlWithLock = $qb->getSQL();
        $this->assertStringContainsString('FOR UPDATE', $sqlWithLock);

        $qb->reset();

        $sqlAfterReset = $qb->getSQL();
        $this->assertStringNotContainsString('FOR UPDATE', $sqlAfterReset);
    }

    protected function tearDownTestTables(Connection $connection, string $databaseName): void
    {
        if ($databaseName === 'mysql') {
            $connection->executeQuery('SET FOREIGN_KEY_CHECKS = 0');
            $connection->executeQuery('DROP TABLE IF EXISTS `test_users`');
            $connection->executeQuery('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $connection->executeQuery('DROP TABLE IF EXISTS "test_users" CASCADE');
        }
    }
}
