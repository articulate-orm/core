<?php

namespace Articulate;

use Articulate\QueryLogger\QueryLoggerInterface;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

class Connection {
    public const MYSQL = 'mysql';

    public const PGSQL = 'pgsql';

    private readonly PDO $pdo;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        private ?QueryLoggerInterface $queryLogger = null,
        bool $persistent = false,
    ) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($persistent) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        $this->pdo = new PDO($this->dsn, $this->user, $this->password, $options);
    }

    public function executeQuery(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($parameters !== []) {
            $parameters = $this->normalizeParameters($parameters);
        }

        $start = hrtime(true);
        $statement->execute($parameters);
        $durationMs = (hrtime(true) - $start) / 1e6;

        $this->queryLogger?->log($sql, $parameters, $durationMs);

        return $statement;
    }

    private function normalizeParameters(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (is_bool($value)) {
                $parameters[$key] = $value ? 1 : 0;
            }
        }

        return $parameters;
    }

    public function getCacheNamespace(): string
    {
        return substr(hash('sha256', $this->dsn), 0, 16);
    }

    public function getDriverName(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollbackTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function createSavepoint(string $name): void
    {
        $this->assertValidSavepointName($name);
        $this->pdo->exec('SAVEPOINT ' . $name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->assertValidSavepointName($name);
        $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->assertValidSavepointName($name);
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
    }

    /**
     * Run a callable inside a transaction, retrying on deadlock / serialization
     * failure with exponential backoff.
     *
     * Only retries when this call owns the transaction: a deadlock aborts the
     * whole transaction on the server, so a nested call cannot meaningfully
     * retry — it just runs the operation in the caller's transaction.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function transactional(callable $operation, int $maxRetries = 3, int $baseDelayMs = 50): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $attempt = 0;

        while (true) {
            $this->pdo->beginTransaction();

            try {
                $result = $operation();
                $this->pdo->commit();

                return $result;
            } catch (PDOException $e) {
                $this->rollbackTransaction();

                if ($attempt < $maxRetries && $this->isRetryable($e)) {
                    usleep($baseDelayMs * 1000 * (2 ** $attempt));
                    $attempt++;

                    continue;
                }

                throw $e;
            } catch (\Throwable $e) {
                $this->rollbackTransaction();

                throw $e;
            }
        }
    }

    private function isRetryable(PDOException $e): bool
    {
        // SQLSTATE: 40001 serialization failure, 40P01 PostgreSQL deadlock_detected
        if (in_array($e->getCode(), ['40001', '40P01'], true)) {
            return true;
        }

        // MySQL driver codes: 1213 deadlock found, 1205 lock wait timeout
        $driverCode = $e->errorInfo[1] ?? null;

        return in_array($driverCode, [1213, 1205], true);
    }

    private function assertValidSavepointName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid savepoint name '{$name}'.");
        }
    }

    public function setQueryLogger(QueryLoggerInterface $queryLogger): void
    {
        $this->queryLogger = $queryLogger;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo->lastInsertId($name);
    }
}
