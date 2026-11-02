<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Connection;
use Articulate\Modules\EntityManager\DeferredImplicitStrategy;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\EntityManager\EntityState;
use Articulate\Modules\EntityManager\Proxy\ProxyInterface;
use Articulate\Modules\EntityManager\UnitOfWork;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestCustomPrimaryKeyEntity;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestEntity;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestPrimaryKeyEntity;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[Entity]
class TestEntityForRemoval {
    #[PrimaryKey]
    public int $id = 1;
}

#[Entity]
class EntityManagerTestEntity {
    public int $id;

    public string $name;
}

#[Entity]
class EntityManagerTrackedEntity {
    #[PrimaryKey]
    public int $id = 1;

    #[Property]
    public string $name = 'Original';
}

class EntityManagerTest extends TestCase {
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = new EntityManager($this->createStub(Connection::class));
    }

    public function testEntityManagerCreation(): void
    {
        $this->assertInstanceOf(EntityManager::class, $this->entityManager);
        $this->assertInstanceOf(UnitOfWork::class, $this->entityManager->getActiveUnitOfWork());
    }

    public function testCreateUnitOfWork(): void
    {
        $unitOfWork = $this->entityManager->createUnitOfWork();

        $this->assertInstanceOf(UnitOfWork::class, $unitOfWork);
        $this->assertNotSame($this->entityManager->getActiveUnitOfWork(), $unitOfWork);
    }

    public function testPersistAndFlush(): void
    {
        $entity = new EntityManagerTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $unitOfWork = $this->entityManager->getActiveUnitOfWork();
        $this->assertEquals(EntityState::MANAGED, $unitOfWork->getEntityState($entity));
    }

    public function testRemoveAndFlush(): void
    {
        $entity = new TestEntityForRemoval();

        $this->entityManager->persist($entity);
        $this->entityManager->remove($entity);
        $this->entityManager->flush();

        $unitOfWork = $this->entityManager->getActiveUnitOfWork();
        $this->assertEquals(EntityState::REMOVED, $unitOfWork->getEntityState($entity));
    }

    public function testClear(): void
    {
        $entity = new EntityManagerTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $this->entityManager->persist($entity);

        $unitOfWork = $this->entityManager->getActiveUnitOfWork();
        $this->assertEquals(EntityState::MANAGED, $unitOfWork->getEntityState($entity));

        $this->entityManager->clear();

        $this->assertEquals(EntityState::DETACHED, $unitOfWork->getEntityState($entity));
    }

    public function testDetachMarksEntityDetachedAcrossUnitOfWorks(): void
    {
        $entity = new EntityManagerTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $scopedUow = $this->entityManager->createUnitOfWork();
        $scopedUow->persist($entity);

        $this->entityManager->detach($entity);

        $this->assertEquals(EntityState::DETACHED, $scopedUow->getEntityState($entity));
        $this->assertSame([], $scopedUow->getManagedEntities());
    }

    public function testFlushRejectsManagedEntityReferencingDetachedEntity(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('inTransaction')->willReturn(false);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('rollbackTransaction');
        $connection->expects($this->never())->method('executeQuery');

        $entityManager = new EntityManager($connection);

        $author = new EntityManagerDetachedReferenceAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new EntityManagerDetachedReferenceBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;

        $uow = $entityManager->getActiveUnitOfWork();
        $uow->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $uow->registerManaged($book, ['id' => 10, 'title' => 'Book']);
        $entityManager->detach($author);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("relation 'author' references detached entity");

        $entityManager->flush();
    }

    public function testFindReturnsNull(): void
    {
        $connection = $this->createStub(Connection::class);

        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);

        $connection->method('executeQuery')->willReturn($statement);

        $entityManager = new EntityManager($connection);
        $result = $entityManager->find(TestEntity::class, 1);

        $this->assertNull($result);
    }

    public function testFindAllReturnsEmptyArray(): void
    {
        // findAll is not implemented yet, should return empty array
        $result = $this->entityManager->findAll(TestEntity::class);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetReferenceReturnsProxy(): void
    {
        // getReference should return a proxy object
        $proxy = $this->entityManager->getReference(TestEntity::class, 1);

        $this->assertInstanceOf(ProxyInterface::class, $proxy);
    }

    public function testRefreshThrowsExceptionWithMockConnection(): void
    {
        // refresh tries to query the database, which will fail with a mock connection
        $this->expectException(\Exception::class);

        $entity = new EntityManagerTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $this->entityManager->refresh($entity);
    }

    public function testTransactionalExecution(): void
    {
        $executed = false;
        $result = null;

        $callbackResult = $this->entityManager->transactional(function (EntityManager $em) use (&$executed, &$result) {
            $executed = true;
            $result = 'callback executed';

            return $result;
        });

        $this->assertTrue($executed);
        $this->assertEquals('callback executed', $callbackResult);
        $this->assertEquals('callback executed', $result);
    }

    public function testTransactionalRollbackOnException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Test exception');

        $this->entityManager->transactional(function (EntityManager $em) {
            throw new \Exception('Test exception');
        });
    }

    public function testBeginTransaction(): void
    {
        // Should not throw an exception
        $this->entityManager->beginTransaction();
        $this->assertTrue(true);
    }

    public function testBeginTransactionDelegatesToConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');

        $em = new EntityManager($connection);
        $em->beginTransaction();
    }

    public function testCommit(): void
    {
        $this->entityManager->beginTransaction();

        // Should not throw an exception
        $this->entityManager->commit();
        $this->assertTrue(true);
    }

    public function testCommitDelegatesToConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('commit');

        $em = new EntityManager($connection);
        $em->commit();
    }

    public function testRollback(): void
    {
        $this->entityManager->beginTransaction();

        // Should not throw an exception
        $this->entityManager->rollback();
        $this->assertTrue(true);
    }

    public function testRollbackDelegatesToConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('rollbackTransaction');

        $em = new EntityManager($connection);
        $em->rollback();
    }

    public function testTransactionalCallsBeginCommitInOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $calls = [];
        $connection->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
        });
        $connection->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
        });
        $connection->expects($this->never())->method('rollbackTransaction');

        $em = new EntityManager($connection);
        $em->transactional(fn () => 'ok');

        $this->assertSame(['begin', 'commit'], $calls);
    }

    public function testTransactionalRollsBackViaConnectionOnException(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->never())->method('commit');
        $connection->expects($this->once())->method('rollbackTransaction');

        $em = new EntityManager($connection);

        try {
            $em->transactional(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
    }

    public function testSetHydratorPropagatesToReadServiceAndRefreshService(): void
    {
        $connection = $this->createStub(Connection::class);
        $em = new EntityManager($connection);

        $customHydrator = $this->createStub(HydratorInterface::class);
        $em->setHydrator($customHydrator);

        $qb = $em->createQueryBuilder();
        $this->assertSame($customHydrator, $qb->getHydrator(), 'EntityReadService must receive the new hydrator');
        $this->assertSame($customHydrator, $em->getHydrator());
    }

    public function testMultipleUnitOfWorks(): void
    {
        $entity1 = new EntityManagerTestEntity();
        $entity1->id = 1;
        $entity1->name = 'entity1';

        $entity2 = new EntityManagerTestEntity();
        $entity2->id = 2;
        $entity2->name = 'entity2';

        // Create two different UnitOfWork instances
        $uow1 = $this->entityManager->createUnitOfWork();
        $uow2 = $this->entityManager->createUnitOfWork();

        // Persist entities in different UnitOfWork instances
        $uow1->persist($entity1);
        $uow2->persist($entity2);

        // Check that entities are managed in their respective UnitOfWork instances
        $this->assertEquals(EntityState::MANAGED, $uow1->getEntityState($entity1));
        $this->assertEquals(EntityState::MANAGED, $uow2->getEntityState($entity2));

        // Check that default UnitOfWork doesn't have these entities
        $defaultUow = $this->entityManager->getActiveUnitOfWork();
        $this->assertEquals(EntityState::NEW, $defaultUow->getEntityState($entity1));
        $this->assertEquals(EntityState::NEW, $defaultUow->getEntityState($entity2));
    }

    public function testFlushAllUnitOfWorks(): void
    {
        $entity = new EntityManagerTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        // Create a scoped UnitOfWork and persist an entity
        $scopedUow = $this->entityManager->createUnitOfWork();
        $scopedUow->persist($entity);

        // Flush should commit all UnitOfWork instances
        $this->entityManager->flush();

        // The entity should still be managed in its UnitOfWork
        $this->assertEquals(EntityState::MANAGED, $scopedUow->getEntityState($entity));
    }

    public function testClearAllUnitOfWorks(): void
    {
        $entity = new EntityManagerTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        // Create a scoped UnitOfWork and persist an entity
        $scopedUow = $this->entityManager->createUnitOfWork();
        $scopedUow->persist($entity);

        $this->assertEquals(EntityState::MANAGED, $scopedUow->getEntityState($entity));

        // Clear should reset all UnitOfWork instances
        $this->entityManager->clear();

        // The scoped UnitOfWork should be gone, and default should be reset
        $newDefaultUow = $this->entityManager->getActiveUnitOfWork();
        $this->assertEquals(EntityState::NEW, $newDefaultUow->getEntityState($entity));
        $this->assertNotSame($scopedUow, $newDefaultUow);
    }

    public function testClearingScopedUnitOfWorkDoesNotClearDefaultSnapshots(): void
    {
        $defaultUow = $this->entityManager->getActiveUnitOfWork();

        $defaultEntity = new EntityManagerTrackedEntity();
        $defaultEntity->id = 1;
        $defaultEntity->name = 'Original';
        $defaultUow->registerManaged($defaultEntity, ['id' => 1, 'name' => 'Original']);

        $scopedUow = $this->entityManager->createUnitOfWork();
        $scopedEntity = new EntityManagerTrackedEntity();
        $scopedEntity->id = 2;
        $scopedEntity->name = 'Scoped';
        $scopedUow->registerManaged($scopedEntity, ['id' => 2, 'name' => 'Scoped']);

        $scopedUow->clear();
        $defaultEntity->name = 'Changed';

        $changeSets = $defaultUow->getChangeSets();

        $this->assertCount(1, $changeSets['updates']);
        $this->assertSame($defaultEntity, $changeSets['updates'][0]['entity']);
        $this->assertSame(['name' => 'Changed'], $changeSets['updates'][0]['changes']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCustomChangeTrackingStrategy(): void
    {
        $metadataRegistry = new EntityMetadataRegistry();
        $customStrategy = new DeferredImplicitStrategy($metadataRegistry);
        $connection = $this->createMock(Connection::class);

        $em = new EntityManager($connection, $customStrategy);

        $this->assertInstanceOf(EntityManager::class, $em);

        $entity = new EntityManagerTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $em->persist($entity);
        $em->flush();

        $this->assertTrue(true);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHydratorAccess(): void
    {
        $hydrator = $this->entityManager->getHydrator();
        $this->assertInstanceOf(HydratorInterface::class, $hydrator);

        $customHydrator = $this->createStub(HydratorInterface::class);
        $this->entityManager->setHydrator($customHydrator);

        $this->assertSame($customHydrator, $this->entityManager->getHydrator());
    }

    public function testCreateQueryBuilder(): void
    {
        $qb = $this->entityManager->createQueryBuilder();

        $this->assertInstanceOf(QueryBuilder::class, $qb);

        // Test that the query builder can build a simple query
        $sql = $qb->select('id', 'name')->from('users')->getSQL();
        $this->assertEquals('SELECT id, name FROM users', $sql);
    }

    public function testCreateQueryBuilderWithEntityClass(): void
    {
        $qb = $this->entityManager->createQueryBuilder(TestEntity::class);

        $this->assertInstanceOf(QueryBuilder::class, $qb);
        $this->assertEquals(TestEntity::class, $qb->getEntityClass());

        // Should have table automatically resolved
        $sql = $qb->getSQL();
        $this->assertStringContainsString('FROM test_entity', $sql);
    }

    public function testCreateQueryBuilderReturnsFreshInstances(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb2 = $this->entityManager->createQueryBuilder();

        $this->assertInstanceOf(QueryBuilder::class, $qb);
        $this->assertInstanceOf(QueryBuilder::class, $qb2);
        $this->assertNotSame($qb, $qb2);
    }

    public function testQueryBuilderWithHydrator(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb2 = $this->entityManager->createQueryBuilder();

        $this->assertInstanceOf(HydratorInterface::class, $qb->getHydrator());
        $this->assertSame($qb->getHydrator(), $qb2->getHydrator());
    }

    public function testFindSelectsOnlyEntityColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);

        // Capture the SQL query being executed
        $executedSql = null;
        $executedParams = null;
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with($this->callback(function ($sql) use (&$executedSql) {
                $executedSql = $sql;

                return true;
            }), $this->callback(function ($params) use (&$executedParams) {
                $executedParams = $params;

                return true;
            }))
            ->willReturn($statement);

        $entityManager = new EntityManager($connection);
        $entityManager->find(TestPrimaryKeyEntity::class, 1);

        // Verify that only entity columns are selected, not SELECT *
        $this->assertIsString($executedSql);
        $this->assertStringStartsWith('SELECT id, name FROM', $executedSql);
        $this->assertStringNotContainsString('SELECT *', $executedSql);
    }

    public function testFindAllSelectsOnlyEntityColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);

        // Capture the SQL query being executed
        $executedSql = null;
        $executedParams = null;
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with($this->callback(function ($sql) use (&$executedSql) {
                $executedSql = $sql;

                return true;
            }), $this->callback(function ($params) use (&$executedParams) {
                $executedParams = $params;

                return true;
            }))
            ->willReturn($statement);

        $entityManager = new EntityManager($connection);
        $entityManager->findAll(TestPrimaryKeyEntity::class);

        // Verify that only entity columns are selected, not SELECT *
        $this->assertIsString($executedSql);
        $this->assertStringStartsWith('SELECT id, name FROM', $executedSql);
        $this->assertStringNotContainsString('SELECT *', $executedSql);
    }

    public function testFindWithMultipleEntityColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);

        // Capture the SQL query being executed
        $executedSql = null;
        $executedParams = null;
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with($this->callback(function ($sql) use (&$executedSql) {
                $executedSql = $sql;

                return true;
            }), $this->callback(function ($params) use (&$executedParams) {
                $executedParams = $params;

                return true;
            }))
            ->willReturn($statement);

        $entityManager = new EntityManager($connection);
        $entityManager->find(TestCustomPrimaryKeyEntity::class, 1);

        // Verify that all entity columns are selected in correct order
        $this->assertIsString($executedSql);
        $this->assertStringStartsWith('SELECT custom_id, name FROM', $executedSql);
        $this->assertStringNotContainsString('SELECT *', $executedSql);
    }
}

#[Entity]
class EntityManagerDetachedReferenceAuthor {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity]
class EntityManagerDetachedReferenceBook {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $title;

    #[ManyToOne(targetEntity: EntityManagerDetachedReferenceAuthor::class)]
    public ?EntityManagerDetachedReferenceAuthor $author = null;
}
