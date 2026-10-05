<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Modules\Repository\Criteria\AndCriteria;
use Articulate\Modules\Repository\Criteria\EqualsCriteria;
use Articulate\Modules\Repository\Criteria\GreaterThanCriteria;
use Articulate\Modules\Repository\Criteria\LessThanCriteria;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use Articulate\Schema\ManagedEntityStoreInterface;
use Articulate\Tests\DatabaseTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

#[Entity]
class EntityManagerTestEntity {
    public int $id;

    public string $name;
}

#[Entity]
class SubQueryColumnResolutionEntity {
    #[Property]
    public int $id;

    #[Property]
    public string $name;
}

class QueryBuilderTest extends DatabaseTestCase {
    private QueryBuilder $qb;

    private Connection $connection;

    private EntityManager $entityManager;

    #[DataProvider('databaseProvider')]
    public function testBasicSelect(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->getSQL();

        $this->assertEquals('SELECT id, name FROM users', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testSelectAll(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->from('users')
            ->getSQL();

        $this->assertEquals('SELECT * FROM users', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereClause(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->where('id = ?', 1);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT id, name FROM users WHERE id = ?', $sql);
        $this->assertEquals([1], $params);
    }

    /**
     * Mutant: MethodCallRemoval on `$subBuilder->sqlCompiler->setEntityClass(...)` in
     * createSubQueryBuilder(). Without it, a sub-query built via whereExists()/createSubQueryBuilder()
     * loses the parent's entity class, so SqlCompiler::buildSelectClause() can't resolve an
     * empty select to the entity's declared columns and falls back to '*'.
     */
    #[DataProvider('databaseProvider')]
    public function testSubQueryBuilderInheritsEntityClassForColumnResolution(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $registry = new EntityMetadataRegistry();
        $this->qb = new QueryBuilder($this->connection, null, $registry);
        $this->qb->setEntityClass(SubQueryColumnResolutionEntity::class);

        $subQuery = $this->qb->createSubQueryBuilder();
        $subQuery->from('subquerycolumnresolutionentities');

        $this->assertSame('SELECT id, name FROM subquerycolumnresolutionentities', $subQuery->getSQL());
    }

    /**
     * Mutant: MethodCallRemoval on `$this->assertValidFieldIdentifier($alias)` in from().
     * Without it, an invalid alias (e.g. containing SQL-breaking characters) would silently
     * flow through into the compiled FROM clause instead of being rejected.
     */
    #[DataProvider('databaseProvider')]
    public function testFromRejectsInvalidAlias(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid field identifier 'users; DROP TABLE'.");

        $this->qb->from('accounts', 'users; DROP TABLE');
    }

    #[DataProvider('databaseProvider')]
    public function testQueryBuilderExecutesQueriesAgainstDatabase(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $activeColumnType = $databaseName === 'mysql' ? 'TINYINT(1)' : 'BOOLEAN';
        $activeValueFalse = $databaseName === 'mysql' ? 0 : false;
        $activeValueTrue = $databaseName === 'mysql' ? 1 : true;

        $this->connection->executeQuery("CREATE TABLE test_users (id INT, name VARCHAR(255), active {$activeColumnType})");
        $this->connection->executeQuery(
            'INSERT INTO test_users (id, name, active) VALUES (1, ?, ?), (2, ?, ?), (3, ?, ?)',
            ['John', $activeValueTrue, 'Jane', $activeValueFalse, 'Mark', $activeValueTrue]
        );

        $result = $this->qb
            ->select('id', 'name')
            ->from('test_users')
            ->where('active = ?', $activeValueTrue)
            ->orderBy('id')
            ->getResult();

        $this->assertEquals(
            [
                ['id' => 1, 'name' => 'John'],
                ['id' => 3, 'name' => 'Mark'],
            ],
            $result
        );
    }

    #[DataProvider('databaseProvider')]
    public function testMultipleWhereClauses(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->where('active = ?', true)
            ->where('deleted_at IS NULL');

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT id, name FROM users WHERE active = ? AND deleted_at IS NULL', $sql);
        $this->assertEquals([true], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereNotGroup(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->whereNot(function ($q) {
                $q->apply(new AndCriteria([
                    new EqualsCriteria('status', 'active'),
                    new GreaterThanCriteria('age', 18),
                ]));
            });

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE NOT (status = ? AND age > ?)', $sql);
        $this->assertEquals(['active', 18], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testOrWhereNotGroup(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->where('active = ?', true)
            ->orWhere(function ($q) {
                $q->whereNot(function ($q2) {
                    $q2->apply(new AndCriteria([
                        new EqualsCriteria('status', 'blocked'),
                        new LessThanCriteria('age', 21),
                    ]));
                });
            });

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE active = ? OR NOT (status = ? AND age < ?)', $sql);
        $this->assertEquals([true, 'blocked', 21], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testJoin(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('u.id', 'u.name', 'p.title')
            ->from('users', 'u')
            ->join('posts', 'p.user_id = u.id')
            ->getSQL();

        $this->assertEquals('SELECT u.id, u.name, p.title FROM users u JOIN posts ON p.user_id = u.id', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testLeftJoin(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('u.id', 'u.name', 'COUNT(p.id) as post_count')
            ->from('users', 'u')
            ->leftJoin('posts p', 'p.user_id = u.id')
            ->getSQL();

        $this->assertEquals('SELECT u.id, u.name, COUNT(p.id) as post_count FROM users u LEFT JOIN posts p ON p.user_id = u.id', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testLimitAndOffset(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->limit(10)
            ->offset(20)
            ->getSQL();

        $this->assertEquals('SELECT id, name FROM users LIMIT 10 OFFSET 20', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testOrderBy(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->orderBy('name', 'ASC')
            ->orderBy('id', 'DESC')
            ->getSQL();

        $this->assertEquals('SELECT id, name FROM users ORDER BY name ASC, id DESC', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testComplexQuery(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('u.id', 'u.name', 'u.email', 'COUNT(p.id) as post_count')
            ->from('users', 'u')
            ->leftJoin('posts p', 'p.user_id = u.id AND p.published = ?', true)
            ->where('u.active = ?', true)
            ->where('u.created_at > ?', '2023-01-01')
            ->orderBy('u.name')
            ->limit(50);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expected = 'SELECT u.id, u.name, u.email, COUNT(p.id) as post_count FROM users u ' .
                   'LEFT JOIN posts p ON p.user_id = u.id AND p.published = ? ' .
                   'WHERE u.active = ? AND u.created_at > ? ' .
                   'ORDER BY u.name ASC LIMIT 50';

        $this->assertEquals($expected, $sql);
        $this->assertEquals([true, true, '2023-01-01'], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testGetResultReturnsEmptyArrayForNoResults(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        // Create a test table first
        $this->connection->executeQuery('CREATE TABLE test_users (id INT, name VARCHAR(255))');

        $result = $this->qb->select('*')->from('test_users')->getResult();

        $this->assertEquals([], $result);
    }

    #[DataProvider('databaseProvider')]
    public function testGetResultReturnsRawData(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        // Create a test table and insert data
        $this->connection->executeQuery('CREATE TABLE test_users (id INT, name VARCHAR(255))');
        $this->connection->executeQuery('INSERT INTO test_users (id, name) VALUES (1, \'John\'), (2, \'Jane\')');

        $result = $this->qb->select('*')->from('test_users')->getResult();

        $expected = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];

        $this->assertEquals($expected, $result);
    }

    #[DataProvider('databaseProvider')]
    public function testExecuteReturnsRowCount(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        // Create a test table and insert data
        $activeColumnType = $databaseName === 'mysql' ? 'TINYINT(1)' : 'BOOLEAN';
        $activeValueFalse = $databaseName === 'mysql' ? '0' : 'false';
        $activeValueTrue = $databaseName === 'mysql' ? '1' : 'true';
        $this->connection->executeQuery("CREATE TABLE test_users (id INT, name VARCHAR(255), active {$activeColumnType})");
        $this->connection->executeQuery("INSERT INTO test_users (id, name, active) VALUES (1, 'John', {$activeValueFalse}), (2, 'Jane', {$activeValueTrue})");

        // For MySQL, active = 0, for PostgreSQL, active = false
        $activeCondition = $databaseName === 'mysql' ? 'active = 0' : 'active = false';
        $result = $this->qb->from('test_users')->where($activeCondition)->execute();

        $this->assertEquals(1, $result);
    }

    #[DataProvider('databaseProvider')]
    public function testSetAndGetHydrator(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $hydrator = $this->createStub(HydratorInterface::class);

        $this->qb->setHydrator($hydrator);
        $this->assertSame($hydrator, $this->qb->getHydrator());

        // Can set to null
        $this->qb->setHydrator(null);
        $this->assertNull($this->qb->getHydrator());
    }

    #[DataProvider('databaseProvider')]
    public function testConstructorWithHydrator(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();

        $hydrator = $this->createStub(HydratorInterface::class);
        $qb = new QueryBuilder($this->connection, $hydrator);

        $this->assertSame($hydrator, $qb->getHydrator());
    }

    #[DataProvider('databaseProvider')]
    public function testSetEntityClass(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();

        $qb = new QueryBuilder($this->connection);

        $qb->setEntityClass('App\\Entity\\User');
        $this->assertEquals('App\\Entity\\User', $qb->getEntityClass());

        // Should automatically set table name
        $this->assertStringContainsString('users', $qb->getSQL());
    }

    #[DataProvider('databaseProvider')]
    public function testResolveTableName(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();

        $qb = new QueryBuilder($this->connection);

        // Test the private method via reflection
        $reflection = new \ReflectionClass($qb);
        $method = $reflection->getMethod('resolveTableName');
        $method->setAccessible(true);

        $this->assertEquals('users', $method->invoke($qb, 'User'));
        $this->assertEquals('posts', $method->invoke($qb, 'App\\Entity\\Post'));
        $this->assertEquals('comments', $method->invoke($qb, 'MyNamespace\\Comment'));
    }

    #[DataProvider('databaseProvider')]
    public function testEntityClassAutoTableResolution(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();

        $qb = new QueryBuilder($this->connection);
        $qb->setEntityClass('User');

        // Should have set FROM clause automatically
        $sql = $qb->getSQL();
        $this->assertStringContainsString('FROM users', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testGetSingleResult(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        // Create a test table and insert data
        $this->connection->executeQuery('CREATE TABLE test_users (id INT, name VARCHAR(255))');
        $this->connection->executeQuery('INSERT INTO test_users (id, name) VALUES (1, \'John\')');

        $result = $this->qb->select('*')->from('test_users')->getSingleResult();

        $this->assertEquals(['id' => 1, 'name' => 'John'], $result);
    }

    #[DataProvider('databaseProvider')]
    public function testGetSingleResultReturnsNullForNoResults(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        // Create a test table
        $this->connection->executeQuery('CREATE TABLE test_users (id INT, name VARCHAR(255))');

        $result = $this->qb->select('*')->from('test_users')->getSingleResult();

        $this->assertNull($result);
    }

    #[DataProvider('databaseProvider')]
    public function testSetManagedEntityStore(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();

        $managedEntityStore = $this->createStub(ManagedEntityStoreInterface::class);
        $qb = new QueryBuilder($this->connection);

        $result = $qb->setManagedEntityStore($managedEntityStore);

        $this->assertSame($qb, $result); // Should return self for chaining
    }

    #[DataProvider('databaseProvider')]
    public function testGetResultWithHydrationAndManagedEntityStore(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();

        // Create a test table and insert data
        $this->connection->executeQuery('CREATE TABLE test_users (id INT, name VARCHAR(255))');
        $this->connection->executeQuery('INSERT INTO test_users (id, name) VALUES (1, \'John\'), (2, \'Jane\')');

        $hydrator = $this->createMock(HydratorInterface::class);
        $hydrator->expects($this->exactly(2))
            ->method('hydrate')
            ->willReturnCallback(function ($class, $row) {
                $entity = new $class();
                $entity->id = $row['id'];
                $entity->name = $row['name'];

                return $entity;
            });

        $managedEntityStore = $this->createMock(ManagedEntityStoreInterface::class);
        $managedEntityStore->expects($this->exactly(2))
            ->method('registerManaged')
            ->with($this->isInstanceOf(EntityManagerTestEntity::class), $this->isArray());
        $managedEntityStore->method('tryGetById')->willReturn(null);

        $qb = new QueryBuilder($this->connection, $hydrator);
        $qb->setManagedEntityStore($managedEntityStore);
        $qb->setEntityClass(EntityManagerTestEntity::class);

        $result = $qb->select('*')->from('test_users')->getResult();

        $this->assertCount(2, $result);
        $this->assertInstanceOf(EntityManagerTestEntity::class, $result[0]);
        $this->assertInstanceOf(EntityManagerTestEntity::class, $result[1]);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('John', $result[0]->name);
        $this->assertEquals(2, $result[1]->id);
        $this->assertEquals('Jane', $result[1]->name);
    }

    #[DataProvider('databaseProvider')]
    public function testGroupBy(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('category', 'COUNT(*) as count')
            ->from('products')
            ->groupBy('category')
            ->groupBy('status')
            ->getSQL();

        $this->assertEquals('SELECT category, COUNT(*) as count FROM products GROUP BY category, status', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testConstructorWithMetadataRegistry(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();

        $metadataRegistry = $this->createStub(EntityMetadataRegistry::class);
        $qb = new QueryBuilder($this->connection, null, $metadataRegistry);

        // Test that the metadata registry is set (private property, so we test indirectly)
        $this->assertInstanceOf(QueryBuilder::class, $qb);
    }

    #[DataProvider('databaseProvider')]
    public function testRightJoin(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('u.id', 'u.name', 'p.title')
            ->from('users', 'u')
            ->rightJoin('posts p', 'p.user_id = u.id')
            ->getSQL();

        $this->assertEquals('SELECT u.id, u.name, p.title FROM users u RIGHT JOIN posts p ON p.user_id = u.id', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testCrossJoin(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $sql = $this->qb
            ->select('u.name', 'c.name')
            ->from('users', 'u')
            ->crossJoin('categories')
            ->getSQL();

        $this->assertEquals('SELECT u.name, c.name FROM users u CROSS JOIN categories', $sql);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereLike(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->where('name', 'like', '%john%');

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE name LIKE ?', $sql);
        $this->assertEquals(['%john%'], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereNotLike(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->where('email', 'not like', '%.test.%');

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE email NOT LIKE ?', $sql);
        $this->assertEquals(['%.test.%'], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereGreaterThan(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->where('age', '>', 18);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE age > ?', $sql);
        $this->assertEquals([18], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereGreaterThanOrEqual(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('products')
            ->where('price', '>=', 100);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM products WHERE price >= ?', $sql);
        $this->assertEquals([100], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereLessThan(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->where('age', '<', 65);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE age < ?', $sql);
        $this->assertEquals([65], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereLessThanOrEqual(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('products')
            ->where('stock', '<=', 10);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM products WHERE stock <= ?', $sql);
        $this->assertEquals([10], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereNotEqual(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->where('status', '!=', 'banned');

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE status != ?', $sql);
        $this->assertEquals(['banned'], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereExists(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('u.*')
            ->from('users', 'u')
            ->whereExists(
                $this->qb
                    ->createSubQueryBuilder()
                    ->select('1')
                    ->from('posts')
                    ->where('posts.user_id = u.id')
            );

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expected = 'SELECT u.* FROM users u WHERE EXISTS (SELECT 1 FROM posts WHERE posts.user_id = u.id)';
        $this->assertEquals($expected, $sql);
        $this->assertEquals([], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testOrWhereLike(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->where('active = ?', true)
            ->orWhere('name', 'like', '%admin%');

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $this->assertEquals('SELECT * FROM users WHERE active = ? OR name LIKE ?', $sql);
        $this->assertEquals([true, '%admin%'], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereInWithSubquery(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->whereIn(
                'id',
                $this->qb
                    ->createSubQueryBuilder()
                    ->select('user_id')
                    ->from('premium_users')
                    ->where('expires_at > ?', '2024-01-01')
            );

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expected = 'SELECT * FROM users WHERE id IN (SELECT user_id FROM premium_users WHERE expires_at > ?)';
        $this->assertEquals($expected, $sql);
        $this->assertEquals(['2024-01-01'], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testWhereNotInWithSubquery(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('*')
            ->from('users')
            ->whereNotIn(
                'role',
                $this->qb
                    ->createSubQueryBuilder()
                    ->select('role_name')
                    ->from('banned_roles')
            );

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expected = 'SELECT * FROM users WHERE role NOT IN (SELECT role_name FROM banned_roles)';
        $this->assertEquals($expected, $sql);
        $this->assertEquals([], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testSelectSub(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('u.name')
            ->selectSub(
                $this->qb
                    ->createSubQueryBuilder()
                    ->selectRaw('COUNT(*)')
                    ->from('posts')
                    ->where('posts.user_id = u.id'),
                'post_count'
            )
            ->from('users', 'u');

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expected = 'SELECT u.name, (SELECT COUNT(*) FROM posts WHERE posts.user_id = u.id) as post_count FROM users u';
        $this->assertEquals($expected, $sql);
        $this->assertEquals([], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testSelectSubWithoutAlias(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('u.name')
            ->selectSub(
                $this->qb
                    ->createSubQueryBuilder()
                    ->selectRaw('COUNT(*)')
                    ->from('posts')
                    ->where('posts.user_id = u.id')
            )
            ->from('users', 'u');

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expected = 'SELECT u.name, (SELECT COUNT(*) FROM posts WHERE posts.user_id = u.id) FROM users u';
        $this->assertEquals($expected, $sql);
        $this->assertEquals([], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testParameterOrderWithSelectRawAndJoin(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->selectRaw('COUNT(CASE WHEN p.published = ? THEN 1 END) as published_count', true)
            ->select('u.id', 'u.name')
            ->from('users', 'u')
            ->leftJoin('posts p', 'p.user_id = u.id AND p.visibility = ?', 'public')
            ->where('u.active = ?', true)
            ->having('published_count > ?', 5);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expectedSql = 'SELECT COUNT(CASE WHEN p.published = ? THEN 1 END) as published_count, u.id, u.name FROM users u ' .
            'LEFT JOIN posts p ON p.user_id = u.id AND p.visibility = ? ' .
            'WHERE u.active = ? HAVING published_count > ?';

        $this->assertEquals($expectedSql, $sql);
        $this->assertEquals([true, 'public', true, 5], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testParameterOrderWithSelectSubAndWhereIn(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('u.id', 'u.name')
            ->selectSub(
                $this->qb
                    ->createSubQueryBuilder()
                    ->selectRaw('COUNT(*)')
                    ->from('posts')
                    ->where('posts.user_id = u.id')
                    ->where('posts.published = ?', true),
                'post_count'
            )
            ->from('users', 'u')
            ->whereIn(
                'u.role',
                $this->qb
                    ->createSubQueryBuilder()
                    ->select('role')
                    ->from('roles')
                    ->where('roles.active = ?', true)
            )
            ->where('u.active = ?', true);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        $expectedSql = 'SELECT u.id, u.name, (SELECT COUNT(*) FROM posts WHERE posts.user_id = u.id AND posts.published = ?) as post_count ' .
            'FROM users u WHERE u.role IN (SELECT role FROM roles WHERE roles.active = ?) AND u.active = ?';

        $this->assertEquals($expectedSql, $sql);
        $this->assertEquals([true, true, true], $params);
    }

    #[DataProvider('databaseProvider')]
    public function testComplexQueryWithNewFeatures(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->qb = new QueryBuilder($this->connection);

        $qb = $this->qb
            ->select('u.id', 'u.name', 'u.email')
            ->selectSub(
                $this->qb
                    ->createSubQueryBuilder()
                    ->selectRaw('COUNT(*)')
                    ->from('posts')
                    ->where('posts.user_id = u.id')
                    ->where('posts.published = ?', true),
                'published_posts'
            )
            ->from('users', 'u')
            ->leftJoin('profiles p', 'p.user_id = u.id')
            ->where('u.active = ?', true)
            ->where('u.name', 'like', '%john%')
            ->where('u.created_at', '>', '2023-01-01')
            ->whereExists(
                $this->qb
                    ->createSubQueryBuilder()
                    ->select('1')
                    ->from('user_permissions')
                    ->where('user_permissions.user_id = u.id')
                    ->where('user_permissions.permission = ?', 'read')
            )
            ->whereIn('u.role', ['admin', 'moderator'])
            ->orderBy('u.name')
            ->limit(50);

        $sql = $qb->getSQL();
        $params = $qb->getParameters();

        // This is a complex query, so we'll just verify it contains the key elements
        $this->assertStringContainsString('SELECT u.id, u.name, u.email, (SELECT COUNT(*)', $sql);
        $this->assertStringContainsString('LEFT JOIN profiles p ON p.user_id = u.id', $sql);
        $this->assertStringContainsString('WHERE u.active = ?', $sql);
        $this->assertStringContainsString('u.name LIKE ?', $sql);
        $this->assertStringContainsString('u.created_at > ?', $sql);
        $this->assertStringContainsString('EXISTS (SELECT 1', $sql);
        $this->assertStringContainsString('u.role IN (?)', $sql);
        $this->assertStringContainsString('ORDER BY u.name ASC LIMIT 50', $sql);

        $expectedParams = [true, true, '%john%', '2023-01-01', 'read', ['admin', 'moderator']];
        $this->assertEquals($expectedParams, $params);
    }

    public function testOrderByWithInvalidFieldThrowsException(): void
    {
        $connection = $this->createStub(Connection::class);
        $qb = new QueryBuilder($connection);
        $this->expectException(\InvalidArgumentException::class);
        $qb->orderBy('invalid!field', 'ASC');
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
