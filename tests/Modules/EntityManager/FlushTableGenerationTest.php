<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Lifecycle\PostUpdate;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Exceptions\ArticulateException;
use Articulate\Modules\Cache\CollectionCache;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Entity(tableName: 'ftg_widgets')]
class FtgWidget {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name = '';

    public ?\Closure $afterUpdate = null;

    #[PostUpdate]
    public function onPostUpdate(): void
    {
        if ($this->afterUpdate !== null) {
            ($this->afterUpdate)();
        }
    }
}

class FlushTableGenerationTest extends DatabaseTestCase {
    #[DataProvider('databaseProvider')]
    public function testATransactionOwningFlushLeavesTheGenerationUnchangedUntilCommit(string $databaseName): void
    {
        [$em, $generations, $widget] = $this->arrange($databaseName);
        $before = $this->generation($generations);

        $seenBeforeCommit = null;
        $widget->afterUpdate = function () use (&$seenBeforeCommit, $generations): void {
            $seenBeforeCommit = $this->generation($generations);
        };
        $widget->name = 'changed';
        $em->flush();

        $this->assertSame($before, $seenBeforeCommit, 'The generation must not move while the flush transaction is still open.');
        $this->assertNotSame($before, $this->generation($generations), 'The generation must be bumped once the flush has committed.');
    }

    #[DataProvider('databaseProvider')]
    public function testAFlushThatThrowsBumpsNoGeneration(string $databaseName): void
    {
        [$em, $generations, $widget] = $this->arrange($databaseName);
        $before = $this->generation($generations);

        $widget->afterUpdate = static function (): void {
            throw new ArticulateException('callback failure');
        };
        $widget->name = 'changed';

        try {
            $em->flush();
            $this->fail('flush() was expected to rethrow the callback failure.');
        } catch (ArticulateException) {
        }

        $this->assertSame($before, $this->generation($generations), 'A rolled-back flush must not bump any generation.');
    }

    #[DataProvider('databaseProvider')]
    public function testAFlushInsideACallerTransactionBumpsTheGenerationBeforeReturning(string $databaseName): void
    {
        [$em, $generations, $widget] = $this->arrange($databaseName);
        $connection = $this->getCurrentConnection();
        $before = $this->generation($generations);

        $connection->beginTransaction();

        try {
            $widget->name = 'changed';
            $em->flush();

            $this->assertNotSame($before, $this->generation($generations), 'Inside a caller-owned transaction the bump happens at the end of flush().');
        } finally {
            $connection->rollbackTransaction();
        }
    }

    /**
     * @return array{EntityManager, CollectionCache, FtgWidget}
     */
    private function arrange(string $databaseName): array
    {
        $connection = $this->getConnection($databaseName);
        $this->createWidgetTable($connection, $databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $connection->executeQuery('INSERT INTO ftg_widgets (name) VALUES (?)', ['original']);

        $pool = new ArrayAdapter();
        $em = new EntityManager($connection, secondLevelCache: $pool);
        $generations = new CollectionCache($pool, $connection->getCacheNamespace());

        $widget = $em->createQueryBuilder(FtgWidget::class)->getResult()[0];

        return [$em, $generations, $widget];
    }

    private function generation(CollectionCache $generations): int
    {
        return $generations->getTableGenerations(['ftg_widgets'])['ftg_widgets'];
    }

    private function createWidgetTable(Connection $connection, string $databaseName): void
    {
        if ($databaseName === 'pgsql') {
            $connection->executeQuery('DROP TABLE IF EXISTS ftg_widgets CASCADE');
            $connection->executeQuery('CREATE TABLE ftg_widgets (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL)');

            return;
        }

        $connection->executeQuery('DROP TABLE IF EXISTS ftg_widgets');
        $connection->executeQuery('CREATE TABLE ftg_widgets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL)');
    }
}
