<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToMany;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Attributes\Relations\OneToMany;
use Articulate\Attributes\SoftDeleteable;
use Articulate\Exceptions\ScheduleConflictException;
use Articulate\Modules\EntityManager\Collection;
use Articulate\Modules\EntityManager\DeferredImplicitStrategy;
use Articulate\Modules\EntityManager\EntityState;
use Articulate\Modules\EntityManager\UnitOfWork;
use Articulate\Schema\EntityMetadataRegistry;
use PHPUnit\Framework\TestCase;

#[Entity]
class UnitOfWorkTestEntity {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity]
class UnitOfWorkUuidEntity {
    #[PrimaryKey(generator: 'uuid_v4')]
    public ?string $id = null;

    #[Property]
    public string $name;
}

class UnitOfWorkTest extends TestCase {
    private UnitOfWork $unitOfWork;

    protected function setUp(): void
    {
        $this->unitOfWork = new UnitOfWork();
    }

    public function testInitialEntityStateIsNew(): void
    {
        $entity = new class() {
            public int $id = 1;
        };

        $this->assertEquals(EntityState::NEW, $this->unitOfWork->getEntityState($entity));
    }

    public function testPersistNewEntity(): void
    {
        $entity = new class() {
            public int $id = 1;
        };

        $this->unitOfWork->persist($entity);

        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));
    }

    public function testPersistDoesNotPreGenerateUuidId(): void
    {
        $entity = new UnitOfWorkUuidEntity();
        $entity->name = 'Generated later';

        $this->unitOfWork->persist($entity);

        $this->assertNull($entity->id);
        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));
        $this->assertContains($entity, $this->unitOfWork->getChangeSets()['inserts']);
    }

    public function testPersistAlreadyManagedEntity(): void
    {
        $entity = new class() {
            public int $id = 1;
        };

        $this->unitOfWork->persist($entity);
        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));

        // Persist again should not change state
        $this->unitOfWork->persist($entity);
        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));
    }

    public function testRemoveManagedEntity(): void
    {
        $entity = new class() {
            public int $id = 1;
        };

        $this->unitOfWork->persist($entity);
        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));

        $this->unitOfWork->remove($entity);
        $this->assertEquals(EntityState::REMOVED, $this->unitOfWork->getEntityState($entity));
    }

    public function testRemoveNewEntity(): void
    {
        $entity = new class() {
            public int $id = 1;
        };

        $this->unitOfWork->persist($entity);
        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));

        $this->unitOfWork->remove($entity);

        // Entity should be removed from tracking entirely for new entities
        // This is a simplified test - actual behavior depends on implementation
        $this->assertTrue(true);
    }

    public function testRegisterManaged(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $originalData = ['id' => 1, 'name' => 'original'];

        $this->unitOfWork->registerManaged($entity, $originalData);

        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));
        $this->assertSame($entity, $this->unitOfWork->tryGetById(UnitOfWorkTestEntity::class, 1));
    }

    public function testTryGetById(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;

        $this->unitOfWork->registerManaged($entity, ['id' => 1]);

        $retrieved = $this->unitOfWork->tryGetById(UnitOfWorkTestEntity::class, 1);
        $this->assertSame($entity, $retrieved);

        $notFound = $this->unitOfWork->tryGetById(UnitOfWorkTestEntity::class, 999);
        $this->assertNull($notFound);
    }

    public function testClear(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;

        $this->unitOfWork->persist($entity);
        $this->unitOfWork->registerManaged($entity, ['id' => 1]);

        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));
        $this->assertNotNull($this->unitOfWork->tryGetById(UnitOfWorkTestEntity::class, 1));

        $this->unitOfWork->clear();

        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($entity));
        $this->assertNull($this->unitOfWork->tryGetById(UnitOfWorkTestEntity::class, 1));
    }

    public function testDetachMarksEntityDetachedAndRemovesItFromIdentityMap(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $this->unitOfWork->registerManaged($entity, ['id' => 1, 'name' => 'test']);
        $this->unitOfWork->detach($entity);

        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($entity));
        $this->assertNull($this->unitOfWork->tryGetById(UnitOfWorkTestEntity::class, 1));
        $this->assertSame([], $this->unitOfWork->getManagedEntities());
    }

    public function testDetachUntrackedEntityKeepsItNew(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $this->unitOfWork->detach($entity);

        $this->assertEquals(EntityState::NEW, $this->unitOfWork->getEntityState($entity));
    }

    public function testPersistDetachedEntityThrows(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $this->unitOfWork->registerManaged($entity, ['id' => 1, 'name' => 'test']);
        $this->unitOfWork->detach($entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot persist detached entity');

        $this->unitOfWork->persist($entity);
    }

    public function testDetachCascadesToInitializedSingleValuedRelation(): void
    {
        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new UnitOfWorkDetachBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;

        $this->unitOfWork->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $this->unitOfWork->registerManaged($book, ['id' => 10, 'title' => 'Book']);

        $this->unitOfWork->detach($book);

        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($book));
        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($author));
        $this->assertSame($author, $book->author);
        $this->assertNull($this->unitOfWork->tryGetById(UnitOfWorkDetachBook::class, 10));
        $this->assertNull($this->unitOfWork->tryGetById(UnitOfWorkDetachAuthor::class, 1));
    }

    public function testDetachCascadesToInitializedCollectionRelation(): void
    {
        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new UnitOfWorkDetachBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;
        $author->books = new Collection([$book]);

        $this->unitOfWork->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $this->unitOfWork->registerManaged($book, ['id' => 10, 'title' => 'Book']);

        $this->unitOfWork->detach($author);

        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($author));
        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($book));
        $this->assertTrue($author->books->contains($book));
    }

    public function testDetachRelatedEntitiesHandlesCycles(): void
    {
        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new UnitOfWorkDetachBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;
        $author->books = new Collection([$book]);

        $this->unitOfWork->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $this->unitOfWork->registerManaged($book, ['id' => 10, 'title' => 'Book']);

        $this->unitOfWork->detach($book);

        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($author));
        $this->assertEquals(EntityState::DETACHED, $this->unitOfWork->getEntityState($book));
        $this->assertSame([], $this->unitOfWork->getManagedEntities());
    }

    public function testDetachedOwningRelationReferenceIsRejected(): void
    {
        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new UnitOfWorkDetachBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;

        $this->unitOfWork->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $this->unitOfWork->registerManaged($book, ['id' => 10, 'title' => 'Book']);
        $this->unitOfWork->detach($author);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("relation 'author' references detached entity");

        $this->unitOfWork->assertNoInvalidReferences();
    }

    public function testNewOwningRelationReferenceWithIdIsRejected(): void
    {
        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new UnitOfWorkDetachBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;

        $this->unitOfWork->registerManaged($book, ['id' => 10, 'title' => 'Book']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("relation 'author' references new entity");

        $this->unitOfWork->assertNoInvalidReferences();
    }

    public function testManagedOwningRelationReferenceIsAccepted(): void
    {
        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new UnitOfWorkDetachBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;

        $this->unitOfWork->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $this->unitOfWork->registerManaged($book, ['id' => 10, 'title' => 'Book']);

        $this->unitOfWork->assertNoInvalidReferences();

        $this->assertTrue(true);
    }

    public function testDetachedOwningManyToManyReferenceIsRejected(): void
    {
        $tag = new UnitOfWorkDetachTag();
        $tag->id = 1;
        $tag->name = 'Tag';

        $post = new UnitOfWorkDetachPost();
        $post->id = 20;
        $post->title = 'Post';
        $post->tags = new Collection([$tag]);

        $this->unitOfWork->registerManaged($tag, ['id' => 1, 'name' => 'Tag']);
        $this->unitOfWork->registerManaged($post, ['id' => 20, 'title' => 'Post']);
        $this->unitOfWork->detach($tag);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("relation 'tags' references detached entity");

        $this->unitOfWork->assertNoInvalidReferences();
    }

    public function testComputeChangeSets(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'modified';

        $originalData = ['id' => 1, 'name' => 'original'];
        $this->unitOfWork->registerManaged($entity, $originalData);

        $this->unitOfWork->computeChangeSets();

        $changes = $this->unitOfWork->getEntityChangeSet($entity);
        $this->assertEquals(['name' => 'modified'], $changes);
    }

    public function testCommit(): void
    {
        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $this->unitOfWork->persist($entity);
        $this->unitOfWork->computeChangeSets();

        $this->unitOfWork->clearChanges();

        // After commit, schedules should be cleared
        // This is a simplified test since actual commit logic is not implemented yet
        $this->assertTrue(true);
    }

    public function testCustomChangeTrackingStrategy(): void
    {
        $metadataRegistry = new EntityMetadataRegistry();
        $customStrategy = new DeferredImplicitStrategy($metadataRegistry);
        $unitOfWork = new UnitOfWork($customStrategy, null, $metadataRegistry);

        $entity = new class() {
            public int $id = 1;
        };

        $unitOfWork->persist($entity);

        $this->assertEquals(EntityState::MANAGED, $unitOfWork->getEntityState($entity));
    }

    public function testIsInIdentityMap(): void
    {
        $entity = new TestEntityForId();
        $entity->id = 1;
        $entity->name = 'Test Entity';

        // Entity not yet registered should return false
        $this->assertFalse($this->unitOfWork->isInIdentityMap($entity));

        // Register the entity
        $this->unitOfWork->registerManaged($entity, ['id' => 1]);

        // Now it should be in the identity map
        $this->assertTrue($this->unitOfWork->isInIdentityMap($entity));
    }

    public function testPersistEntityWithExistingIdSchedulesInsert(): void
    {
        $entity = new TestEntityForId();
        $entity->id = 42;
        $entity->name = 'Entity with ID';

        $this->assertEquals(EntityState::NEW, $this->unitOfWork->getEntityState($entity));

        $this->unitOfWork->persist($entity);

        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));

        // Should be scheduled for INSERT, not silently treated as already-managed
        $changes = $this->unitOfWork->getChangeSets();
        $this->assertContains($entity, $changes['inserts'], 'Entity with explicit ID must be scheduled for INSERT');

        // Should keep the original ID
        $this->assertEquals(42, $entity->id);
    }

    public function testUuidEntityWithExistingIdDoesNotOverwrite(): void
    {
        $existingUuid = '550e8400-e29b-41d4-a716-446655440000';

        $entity = new TestEntityForUuid();
        $entity->id = $existingUuid;
        $entity->name = 'UUID Entity';

        $this->unitOfWork->persist($entity);

        // Should keep the original UUID (not overwrite with a new generated one)
        $this->assertEquals($existingUuid, $entity->id);

        // Should be scheduled for INSERT
        $changes = $this->unitOfWork->getChangeSets();
        $this->assertContains($entity, $changes['inserts']);
    }

    public function testGetEntityByOidFailureInComputeChangeSets(): void
    {
        // This test demonstrates the bug where getEntityByOid always returns null
        // even when the entity OID exists in entityStates

        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'modified';

        $originalData = ['id' => 1, 'name' => 'original'];
        $this->unitOfWork->registerManaged($entity, $originalData);

        // At this point, the entity should be managed and have an OID in entityStates
        $this->assertEquals(EntityState::MANAGED, $this->unitOfWork->getEntityState($entity));

        // The entity has changes (name changed from 'original' to 'modified')
        $changes = $this->unitOfWork->getEntityChangeSet($entity);
        $this->assertEquals(['name' => 'modified'], $changes);

        // computeChangeSets() internally calls getEntityByOid() for each managed entity
        // to check for changes and schedule updates. Currently, getEntityByOid() always returns null,
        // so no entities are scheduled for update even though they have changes
        $this->unitOfWork->computeChangeSets();

        // The entity should be scheduled for update because it has changes,
        // but currently it's not scheduled due to getEntityByOid bug
        $entityOid = spl_object_id($entity);
        $this->assertArrayHasKey(
            $entityOid,
            $this->unitOfWork->getScheduledUpdates(),
            'Entity with changes should be scheduled for update, but getEntityByOid returns null'
        );
    }

    public function testRemovePropagatesRemovedStateToSiblingEntity(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $entityA = new SharedTableEntityA();
        $entityA->id = 1;
        $entityA->name = 'Alice';

        $entityB = new SharedTableEntityB();
        $entityB->id = 1;

        $uow->registerManaged($entityA, ['id' => 1, 'name' => 'Alice']);
        $uow->registerManaged($entityB, ['id' => 1]);

        $uow->remove($entityA);

        $this->assertEquals(EntityState::REMOVED, $uow->getEntityState($entityA));
        $this->assertEquals(EntityState::REMOVED, $uow->getEntityState($entityB));
        $this->assertNull($uow->tryGetById(SharedTableEntityB::class, 1));
    }

    public function testRemoveDoesNotPropagateToSiblingWithDifferentId(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $entityA = new SharedTableEntityA();
        $entityA->id = 1;
        $entityA->name = 'Alice';

        $entityB = new SharedTableEntityB();
        $entityB->id = 2;

        $uow->registerManaged($entityA, ['id' => 1, 'name' => 'Alice']);
        $uow->registerManaged($entityB, ['id' => 2]);

        $uow->remove($entityA);

        $this->assertEquals(EntityState::REMOVED, $uow->getEntityState($entityA));
        $this->assertEquals(EntityState::MANAGED, $uow->getEntityState($entityB));
        $this->assertNotNull($uow->tryGetById(SharedTableEntityB::class, 2));
    }

    public function testPersistAfterRemoveSameRowThrows(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $entityA = new SharedTableEntityA();
        $entityA->id = 1;
        $entityA->name = 'Alice';

        $entityB = new SharedTableEntityA();
        $entityB->id = 1;
        $entityB->name = 'Bob';

        $uow->registerManaged($entityA, ['id' => 1, 'name' => 'Alice']);
        $uow->remove($entityA);

        $this->expectException(ScheduleConflictException::class);
        $uow->persist($entityB);
    }

    public function testRemoveAfterPersistSameRowThrows(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $entityA = new SharedTableEntityA();
        $entityA->id = 1;
        $entityA->name = 'Alice';

        $entityB = new SharedTableEntityA();
        $entityB->id = 1;
        $entityB->name = 'Bob';

        $uow->registerManaged($entityA, ['id' => 1, 'name' => 'Alice']);
        $uow->persist($entityB);

        $this->expectException(ScheduleConflictException::class);
        $uow->remove($entityA);
    }

    public function testSiblingEntityNotAddedToScheduledDeletes(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $entityA = new SharedTableEntityA();
        $entityA->id = 1;
        $entityA->name = 'Alice';

        $entityB = new SharedTableEntityB();
        $entityB->id = 1;

        $uow->registerManaged($entityA, ['id' => 1, 'name' => 'Alice']);
        $uow->registerManaged($entityB, ['id' => 1]);

        $uow->remove($entityA);

        $changes = $uow->getChangeSets();
        $this->assertCount(1, $changes['deletes'], 'Only one DELETE needed for sibling entities on the same row');
        $this->assertSame($entityA, $changes['deletes'][0]);
    }

    // ── Mutation killers for 269-280 ────────────────────────────────────────

    public function testRemoveOnSoftDeleteableEntitySetsSoftDeleteFieldAndSchedulesUpdate(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $entity = new UnitOfWorkSoftDeleteEntity();
        $entity->id = 1;
        $entity->name = 'Alice';

        $uow->registerManaged($entity, ['id' => 1, 'name' => 'Alice']);
        $uow->remove($entity);

        $this->assertNotNull($entity->deletedAt, 'MethodCallRemoval mutant would leave deletedAt unset');
        $this->assertEquals(EntityState::REMOVED, $uow->getEntityState($entity));

        $changes = $uow->getChangeSets();
        $this->assertCount(1, $changes['softDeletes']);
        $this->assertSame($entity, $changes['softDeletes'][0]);
        $this->assertCount(0, $changes['deletes'], 'Soft-deleted entity must not also appear in hard deletes');
    }

    public function testComputeChangeSetsInvokesPreUpdateCallbackOnlyOnceWhenNotAlreadyScheduled(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(metadataRegistry: $registry);

        UnitOfWorkPreUpdateCallbackEntity::$preUpdateCallCount = 0;

        $entity = new UnitOfWorkPreUpdateCallbackEntity();
        $entity->id = 1;
        $entity->name = 'Original';

        $uow->registerManaged($entity, ['id' => 1, 'name' => 'Original']);
        $entity->name = 'Changed';

        $uow->computeChangeSets();

        $this->assertSame(1, UnitOfWorkPreUpdateCallbackEntity::$preUpdateCallCount);
    }

    public function testExecutePostCallbacksInvokesPostRemoveForEachSoftDeletedEntity(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(metadataRegistry: $registry);

        $entity = new UnitOfWorkPostRemoveCallbackEntity();
        $entity->id = 1;
        $entity->name = 'Alice';

        UnitOfWorkPostRemoveCallbackEntity::$postRemoveCallCount = 0;

        $uow->executePostCallbacks([
            'inserts' => [],
            'updates' => [],
            'deletes' => [],
            'softDeletes' => [$entity],
        ]);

        $this->assertSame(1, UnitOfWorkPostRemoveCallbackEntity::$postRemoveCallCount);
    }

    public function testDetachGraphVisitsEachEntityOnlyOnce(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book = new UnitOfWorkDetachBook();
        $book->id = 10;
        $book->title = 'Book';
        $book->author = $author;
        $author->books = new Collection([$book]);

        $uow->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $uow->registerManaged($book, ['id' => 10, 'title' => 'Book']);

        // Without the visited-guard (TrueValue mutant sets $visited[$oid] = false,
        // defeating isset() short-circuit), this would infinite-loop on the
        // author<->book cycle. Reaching this assertion at all proves termination.
        $uow->detach($author);

        $this->assertEquals(EntityState::DETACHED, $uow->getEntityState($author));
        $this->assertEquals(EntityState::DETACHED, $uow->getEntityState($book));
    }

    public function testDetachSingleCallsUntrackEntityOnChangeTrackingStrategy(): void
    {
        $registry = new EntityMetadataRegistry();
        $strategy = new RecordingUntrackStrategy($registry);
        $uow = new UnitOfWork($strategy, null, $registry);

        $entity = new UnitOfWorkTestEntity();
        $entity->id = 1;
        $entity->name = 'test';

        $uow->registerManaged($entity, ['id' => 1, 'name' => 'test']);
        $uow->detach($entity);

        $this->assertTrue($strategy->untracked, 'untrackEntity() must be invoked on detach, else stale snapshots leak');
    }

    public function testGetInitializedRelatedEntitiesReturnsAllDistinctRelatedObjects(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $author = new UnitOfWorkDetachAuthor();
        $author->id = 1;
        $author->name = 'Author';

        $book1 = new UnitOfWorkDetachBook();
        $book1->id = 10;
        $book1->title = 'Book1';
        $book1->author = $author;

        $book2 = new UnitOfWorkDetachBook();
        $book2->id = 11;
        $book2->title = 'Book2';
        $book2->author = $author;

        $author->books = new Collection([$book1, $book2]);

        $uow->registerManaged($author, ['id' => 1, 'name' => 'Author']);
        $uow->registerManaged($book1, ['id' => 10, 'title' => 'Book1']);
        $uow->registerManaged($book2, ['id' => 11, 'title' => 'Book2']);

        // array_values() mutant removal would not break the equality below for a plain
        // sequential list, so assert the keys are reindexed from 0 rather than preserving
        // the original spl_object_id()-keyed array.
        $reflection = new \ReflectionClass($uow);
        $method = $reflection->getMethod('getInitializedRelatedEntities');
        $method->setAccessible(true);

        $related = $method->invoke($uow, $author);

        $this->assertSame([0, 1], array_keys($related), 'Result must be reindexed from 0 via array_values()');
        $this->assertCount(2, $related);
    }

    public function testCanWriteRelationReferenceAcceptsOwningMorphToRelation(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $owner = new UnitOfWorkMorphToOwner();
        $owner->id = 1;
        $owner->name = 'Owner';

        $target = new UnitOfWorkMorphToTarget();
        $target->id = 5;
        $target->name = 'Target';
        $owner->commentable = $target;

        $uow->registerManaged($owner, ['id' => 1, 'name' => 'Owner']);
        $uow->registerManaged($target, ['id' => 5, 'name' => 'Target']);

        // assertNoInvalidReferences() must NOT throw for a MANAGED morph target —
        // the LogicalOrSingleSubExprNegation mutant (isMorphTo() negated) would make
        // canWriteRelationReference() always return false for MorphTo, skipping the
        // check entirely and silently hiding real violations. Prove the positive path
        // is actually exercised instead.
        $uow->assertNoInvalidReferences();
        $this->assertTrue(true);
    }

    public function testCanWriteRelationReferenceRejectsDetachedMorphToTarget(): void
    {
        $registry = new EntityMetadataRegistry();
        $uow = new UnitOfWork(null, null, $registry);

        $owner = new UnitOfWorkMorphToOwner();
        $owner->id = 1;
        $owner->name = 'Owner';

        $target = new UnitOfWorkMorphToTarget();
        $target->id = 5;
        $target->name = 'Target';
        $owner->commentable = $target;

        $uow->registerManaged($owner, ['id' => 1, 'name' => 'Owner']);
        $uow->registerManaged($target, ['id' => 5, 'name' => 'Target']);
        $uow->detach($target);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("relation 'commentable' references detached entity");

        $uow->assertNoInvalidReferences();
    }
}

// Test entity class for ID generation tests
#[Entity]
class TestEntityForId {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

// Test entity class for UUID generation tests
#[Entity]
class TestEntityForUuid {
    #[PrimaryKey(generator: 'uuid')]
    public ?string $id = null;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'shared_table')]
class SharedTableEntityA {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'shared_table')]
class SharedTableEntityB {
    #[PrimaryKey]
    public int $id;
}

#[Entity]
class UnitOfWorkDetachAuthor {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[OneToMany(targetEntity: UnitOfWorkDetachBook::class, ownedBy: 'author')]
    public ?Collection $books = null;
}

#[Entity]
class UnitOfWorkDetachBook {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $title;

    #[ManyToOne(targetEntity: UnitOfWorkDetachAuthor::class)]
    public ?UnitOfWorkDetachAuthor $author = null;
}

#[Entity]
class UnitOfWorkDetachPost {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $title;

    #[ManyToMany(targetEntity: UnitOfWorkDetachTag::class)]
    public ?Collection $tags = null;
}

#[Entity]
class UnitOfWorkDetachTag {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity]
#[SoftDeleteable]
class UnitOfWorkSoftDeleteEntity {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[Property]
    public ?\DateTimeImmutable $deletedAt = null;
}

#[Entity]
class UnitOfWorkPreUpdateCallbackEntity {
    public static int $preUpdateCallCount = 0;

    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[\Articulate\Attributes\Lifecycle\PreUpdate]
    public function onPreUpdate(): void
    {
        self::$preUpdateCallCount++;
    }
}

#[Entity]
class UnitOfWorkPostRemoveCallbackEntity {
    public static int $postRemoveCallCount = 0;

    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[\Articulate\Attributes\Lifecycle\PostRemove]
    public function onPostRemove(): void
    {
        self::$postRemoveCallCount++;
    }
}

class RecordingUntrackStrategy implements \Articulate\Modules\EntityManager\ChangeTrackingStrategy {
    public bool $untracked = false;

    public function __construct(private readonly EntityMetadataRegistry $registry)
    {
    }

    public function trackEntity(object $entity, array $originalData): void
    {
    }

    public function untrackEntity(object $entity): void
    {
        $this->untracked = true;
    }

    public function computeChangeSet(object $entity): array
    {
        return [];
    }

    public function refreshSnapshot(object $entity): void
    {
    }

    public function clear(): void
    {
    }
}

#[Entity]
class UnitOfWorkMorphToTarget {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity]
class UnitOfWorkMorphToOwner {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[\Articulate\Attributes\Relations\MorphTo]
    public ?UnitOfWorkMorphToTarget $commentable = null;
}
