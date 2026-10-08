<?php

namespace Articulate\Tests;

use Articulate\Connection;

class ConnectionTest extends AbstractTestCase {
    public function testCommitWithoutTransactionDoesNotThrow(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $connection->commit();
        $this->assertFalse($connection->inTransaction());
    }

    public function testBeginTransactionTwiceDoesNotNest(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $connection->beginTransaction();
        $connection->beginTransaction();

        $this->assertTrue($connection->inTransaction());

        $connection->commit();
        $this->assertFalse($connection->inTransaction());
    }

    public function testRollbackWithoutTransactionDoesNotThrow(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $connection->rollbackTransaction();
        $this->assertFalse($connection->inTransaction());
    }

    public function testInTransactionReturnsCorrectState(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $this->assertFalse($connection->inTransaction());

        $connection->beginTransaction();
        $this->assertTrue($connection->inTransaction());

        $connection->commit();
        $this->assertFalse($connection->inTransaction());
    }

    public function testNormalizeBoolParameters(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        $connection->executeQuery('CREATE TEMPORARY TABLE __test_bool (id INT, active TINYINT(1))');
        $connection->executeQuery(
            'INSERT INTO __test_bool (id, active) VALUES (:id, :active)',
            ['id' => 1, 'active' => true],
        );

        $result = $connection->executeQuery(
            'SELECT active FROM __test_bool WHERE id = :id AND active = :active',
            ['id' => 1, 'active' => true],
        );

        $row = $result->fetch();
        $this->assertNotFalse($row);
        $this->assertEquals(1, $row['active']);
    }

    public function testPersistentConnectionExecutesQueriesCorrectly(): void
    {
        $host = getenv('DATABASE_HOST') ?: '127.0.0.1';
        $name = getenv('DATABASE_NAME') ?: 'articulate_test';
        $user = getenv('DATABASE_USER') ?: 'root';
        $password = getenv('DATABASE_PASSWORD') ?: '';

        $connection = new Connection(
            'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
            $user,
            $password,
            persistent: true,
        );

        $result = $connection->executeQuery('SELECT 42 AS val');
        $row = $result->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(42, (int) $row['val']);
    }

    public function testSavepointRollbackKeepsEarlierWork(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $connection->executeQuery('CREATE TEMPORARY TABLE __test_sp (id INT) ENGINE=InnoDB');

        $connection->beginTransaction();
        $connection->executeQuery('INSERT INTO __test_sp (id) VALUES (1)');
        $connection->createSavepoint('sp1');
        $connection->executeQuery('INSERT INTO __test_sp (id) VALUES (2)');
        $connection->rollbackToSavepoint('sp1');
        $connection->releaseSavepoint('sp1');
        $connection->commit();

        $rows = $connection->executeQuery('SELECT id FROM __test_sp ORDER BY id')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertEquals(1, $rows[0]['id']);
    }

    public function testInvalidSavepointNameThrows(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        $this->expectException(\InvalidArgumentException::class);
        $connection->createSavepoint('bad; DROP TABLE users');
    }

    public function testInvalidReleaseSavepointNameThrows(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        $this->expectException(\InvalidArgumentException::class);
        $connection->releaseSavepoint('bad; DROP TABLE users');
    }

    public function testInvalidRollbackToSavepointNameThrows(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        $this->expectException(\InvalidArgumentException::class);
        $connection->rollbackToSavepoint('bad; DROP TABLE users');
    }

    public function testTransactionalRetriesOnceThenSucceeds(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $attempts = 0;
        $result = $connection->transactional(function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                $deadlock = new \PDOException('Deadlock found when trying to get lock');
                $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw $deadlock;
            }

            return 'ok';
        }, maxRetries: 3, baseDelayMs: 1);

        $this->assertSame('ok', $result);
        $this->assertSame(2, $attempts); // first attempt failed, retry succeeded
        $this->assertFalse($connection->inTransaction());
    }

    public function testTransactionalGivesUpAfterMaxRetries(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $attempts = 0;
        $caught = null;

        try {
            $connection->transactional(function () use (&$attempts): void {
                $attempts++;
                $deadlock = new \PDOException('Deadlock found when trying to get lock');
                $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw $deadlock;
            }, maxRetries: 2, baseDelayMs: 1);
        } catch (\PDOException $e) {
            $caught = $e->getMessage();
        }

        $this->assertSame('Deadlock found when trying to get lock', $caught);
        $this->assertSame(3, $attempts); // initial attempt + 2 retries
        $this->assertFalse($connection->inTransaction());
    }

    public function testTransactionalRetriesOnLockWaitTimeout(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $attempts = 0;
        $result = $connection->transactional(function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                $lockWait = new \PDOException('Lock wait timeout exceeded');
                $lockWait->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

                throw $lockWait;
            }

            return 'ok';
        }, maxRetries: 3, baseDelayMs: 1);

        $this->assertSame('ok', $result);
        $this->assertSame(2, $attempts);
    }

    public function testTransactionalDoesNotRetryNonRetryableError(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $attempts = 0;
        $caught = null;

        try {
            $connection->transactional(function () use (&$attempts): void {
                $attempts++;
                $other = new \PDOException('Syntax error');
                $other->errorInfo = ['42000', 1064, 'Syntax error'];

                throw $other;
            }, maxRetries: 3, baseDelayMs: 1);
        } catch (\PDOException $e) {
            $caught = $e->getMessage();
        }

        $this->assertSame('Syntax error', $caught);
        $this->assertSame(1, $attempts); // non-retryable → no retry
    }

    public function testTransactionalCommitsAndReturnsValue(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $connection->executeQuery('CREATE TEMPORARY TABLE __test_tx (id INT) ENGINE=InnoDB');

        $result = $connection->transactional(function () use ($connection): string {
            $connection->executeQuery('INSERT INTO __test_tx (id) VALUES (7)');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($connection->inTransaction());

        $rows = $connection->executeQuery('SELECT id FROM __test_tx')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertEquals(7, $rows[0]['id']);
    }

    public function testTransactionalRollsBackOnException(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $caught = null;

        try {
            $connection->transactional(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        $this->assertSame('boom', $caught);
        $this->assertFalse($connection->inTransaction());
    }

    public function testTransactionalNestedRunsInCallerTransaction(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $connection->beginTransaction();

        $executed = 0;
        $connection->transactional(function () use (&$executed): void {
            $executed++;
        });

        // Nested call runs the operation but must not commit the caller's transaction
        $this->assertSame(1, $executed);
        $this->assertTrue($connection->inTransaction());

        $connection->rollbackTransaction();
    }

    public function testTransactionalDefaultsToThreeRetries(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $attempts = 0;

        try {
            $connection->transactional(function () use (&$attempts): void {
                $attempts++;

                throw $this->deadlock();
            });
            $this->fail('Expected the deadlock to be rethrown after the retries are exhausted.');
        } catch (\PDOException) {
            // expected
        }

        $this->assertSame(4, $attempts); // initial attempt + 3 default retries
    }

    public function testTransactionalBackoffGrowsExponentially(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $startedAt = hrtime(true);

        try {
            $connection->transactional(function (): void {
                throw $this->deadlock();
            }, maxRetries: 3, baseDelayMs: 100);
            $this->fail('Expected the deadlock to be rethrown after the retries are exhausted.');
        } catch (\PDOException) {
            // expected
        }

        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        // 100 + 200 + 400 = 700ms of backoff; the window is wide enough to tolerate
        // scheduling noise but still rules out linear (300ms) and 3^n (1300ms) growth.
        $this->assertGreaterThan(600, $elapsedMs);
        $this->assertLessThan(1000, $elapsedMs);
    }

    private function deadlock(): \PDOException
    {
        $deadlock = new \PDOException('Deadlock found when trying to get lock');
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        return $deadlock;
    }

    public function testEmulatePreparesIsDisabled(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        $pdo = $this->extractPdo($connection);
        $this->assertFalse($pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
    }

    public function testNonPersistentConnectionDoesNotSetPersistentAttribute(): void
    {
        $host = getenv('DATABASE_HOST') ?: '127.0.0.1';
        $name = getenv('DATABASE_NAME') ?: 'articulate_test';
        $user = getenv('DATABASE_USER') ?: 'root';
        $password = getenv('DATABASE_PASSWORD') ?: '';

        $connection = new Connection(
            'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
            $user,
            $password,
            persistent: false,
        );

        $pdo = $this->extractPdo($connection);
        $this->assertFalse($pdo->getAttribute(\PDO::ATTR_PERSISTENT));
    }

    private function extractPdo(Connection $connection): \PDO
    {
        $reflection = new \ReflectionClass($connection);
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);

        return $property->getValue($connection);
    }

    public function testReleaseSavepointActuallyPreventsFurtherRollbackToIt(): void
    {
        $connection = ConnectionPool::getInstance()->getMysqlConnection();
        $this->assertNotNull($connection);

        if ($connection->inTransaction()) {
            $connection->rollbackTransaction();
        }

        $connection->beginTransaction();
        $connection->createSavepoint('sp_release_test');
        $connection->releaseSavepoint('sp_release_test');

        // The savepoint no longer exists, so rolling back to it must fail.
        $this->expectException(\PDOException::class);

        try {
            $connection->rollbackToSavepoint('sp_release_test');
        } finally {
            $connection->rollbackTransaction();
        }
    }

    protected function setUpTestTables(Connection $connection, string $databaseName): bool
    {
        return true;
    }
}
