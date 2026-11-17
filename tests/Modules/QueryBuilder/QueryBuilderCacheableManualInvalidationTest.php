<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Entity(tableName: 'qb_minv_products')]
class QbManualInvProduct {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public int $stock;
}

#[Entity(tableName: 'qb_minv_tags')]
class QbManualInvTag {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'product_id')]
    public int $productId;
}

/**
 * Writes the unit of work never sees (QueryBuilder DML, raw SQL) and
 * schema-qualified table names must still invalidate cacheable() lists.
 */
class QueryBuilderCacheableManualInvalidationTest extends DatabaseTestCase {
    #[DataProvider('databaseProvider')]
    public function testQueryBuilderBulkUpdateInvalidatesTheList(string $databaseName): void
    {
        [$em] = $this->freshContext($databaseName);
        $this->assertSame(['A', 'B'], $this->inStock($em));

        $em->createQueryBuilder()->update('qb_minv_products')->set('stock', 0)->where('name', 'A')->execute();

        $this->assertSame(['B'], $this->inStock($em));
    }

    #[DataProvider('databaseProvider')]
    public function testQueryBuilderBulkDeleteInvalidatesTheList(string $databaseName): void
    {
        [$em] = $this->freshContext($databaseName);
        $this->assertSame(['A', 'B'], $this->inStock($em));

        $em->createQueryBuilder()->delete('qb_minv_products')->where('name', 'B')->execute();

        $this->assertSame(['A'], $this->inStock($em));
    }

    #[DataProvider('databaseProvider')]
    public function testInvalidateTablesDropsListsAfterARawWrite(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $this->assertSame(['A', 'B'], $this->inStock($em));

        $connection->executeQuery("UPDATE qb_minv_products SET stock = 0 WHERE name = 'A'");
        $this->assertSame(['A', 'B'], $this->inStock($em), 'Raw SQL alone is not detected.');

        $em->invalidateTables('qb_minv_products');

        $this->assertSame(['B'], $this->inStock($em));
    }

    #[DataProvider('databaseProvider')]
    public function testInvalidateCacheNamesDropsOnlyThatNamedList(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $this->assertSame(['A', 'B'], $this->inStock($em, 'stocked'));
        $this->assertSame(['A', 'B'], $this->inStock($em, 'other'));

        $connection->executeQuery("UPDATE qb_minv_products SET stock = 0 WHERE name = 'A'");
        $em->invalidateCacheNames('stocked');

        $this->assertSame(['B'], $this->inStock($em, 'stocked'));
        $this->assertSame(['A', 'B'], $this->inStock($em, 'other'), 'Another name must keep its list.');
    }

    #[DataProvider('databaseProvider')]
    public function testASchemaQualifiedJoinIsInvalidatedByAFlush(string $databaseName): void
    {
        [$em] = $this->freshContext($databaseName);
        $schema = $databaseName === 'pgsql' ? 'public' : (string) $em->getConnection()->executeQuery('SELECT DATABASE()')->fetchColumn();
        $query = static fn (): array => array_map(
            static fn (QbManualInvProduct $product): string => $product->name,
            $em->createQueryBuilder(QbManualInvProduct::class)
                ->join("{$schema}.qb_minv_tags t", 't.product_id = qb_minv_products.id')
                ->orderBy('qb_minv_products.id')
                ->cacheable()
                ->getResult()
        );
        $this->assertSame(['A'], $query());

        $tag = new QbManualInvTag();
        $tag->productId = 2;
        $em->persist($tag);
        $em->flush();
        $em->clear();

        $this->assertSame(['A', 'B'], $query(), 'A flush to the bare table must invalidate a list joined schema-qualified.');
    }

    /**
     * @return list<string>
     */
    private function inStock(EntityManager $em, ?string $name = null): array
    {
        $em->clear();
        $result = $em->createQueryBuilder(QbManualInvProduct::class)
            ->where('stock', '>', 0)
            ->orderBy('id')
            ->cacheable(name: $name)
            ->getResult();

        return array_map(static fn (QbManualInvProduct $product): string => $product->name, $result);
    }

    /**
     * @return array{EntityManager, Connection}
     */
    private function freshContext(string $databaseName): array
    {
        $connection = $this->getConnection($databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $id = $databaseName === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $connection->executeQuery('DROP TABLE IF EXISTS qb_minv_tags');
        $connection->executeQuery("CREATE TABLE qb_minv_tags (id {$id}, product_id INT NOT NULL)");
        $connection->executeQuery('INSERT INTO qb_minv_tags (product_id) VALUES (1)');
        $connection->executeQuery('DROP TABLE IF EXISTS qb_minv_products');
        $connection->executeQuery("CREATE TABLE qb_minv_products (id {$id}, name VARCHAR(255) NOT NULL, stock INT NOT NULL)");
        $connection->executeQuery("INSERT INTO qb_minv_products (name, stock) VALUES ('A', 5), ('B', 3)");

        return [new EntityManager($connection, secondLevelCache: new ArrayAdapter()), $connection];
    }
}
