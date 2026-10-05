<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\ChangeAggregator;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\EntityManager\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class ChangeAggregatorTestLogger extends AbstractLogger {
    /** @var array<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

#[Entity]
class TestEntityForChangeAggregation {
    #[Property]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[Property]
    public bool $active = true;
}

class ChangeAggregatorTest extends TestCase {
    private ChangeAggregator $aggregator;

    private Connection $connection;

    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(Connection::class);
        $this->entityManager = new EntityManager($this->connection);
        $this->aggregator = new ChangeAggregator(
            $this->entityManager->getMetadataRegistry(),
            $this->entityManager->getUpdateConflictResolutionStrategy(),
        );
    }

    public function testAggregateChangesWithEmptyUnitOfWorks(): void
    {
        $result = $this->aggregator->aggregateChanges([]);

        $this->assertEquals([
            'inserts' => [],
            'updates' => [],
            'deletes' => [],
            'softDeletes' => [],
        ], $result);
    }

    public function testAggregateChangesWithSingleUnitOfWork(): void
    {
        // Use the real UnitOfWork from EntityManager
        $unitOfWork = $this->entityManager->getActiveUnitOfWork();

        // Create and persist entities
        $entity1 = new TestEntityForChangeAggregation();
        $entity1->id = 1;
        $entity1->name = 'Entity1';

        $this->entityManager->persist($entity1);

        // Get the changes that were aggregated
        $result = $this->aggregator->aggregateChanges([$unitOfWork]);

        $this->assertCount(1, $result['inserts']);
        $this->assertCount(0, $result['updates']);
        $this->assertCount(0, $result['deletes']);

        $this->assertSame($entity1, $result['inserts'][0]);
    }

    public function testAggregateChangesWithMultipleUnitOfWorks(): void
    {
        // Use multiple UnitOfWorks
        $unitOfWork1 = $this->entityManager->getActiveUnitOfWork();
        $unitOfWork2 = $this->entityManager->createUnitOfWork();

        $entity1 = new TestEntityForChangeAggregation();
        $entity1->id = 1;
        $entity1->name = 'Entity1';

        $entity2 = new TestEntityForChangeAggregation();
        $entity2->id = 2;
        $entity2->name = 'Entity2';

        $this->entityManager->persist($entity1);
        // Note: entity2 is in a different UOW, but this is simplified for testing

        $result = $this->aggregator->aggregateChanges([$unitOfWork1, $unitOfWork2]);

        $this->assertCount(1, $result['inserts']);
        $this->assertCount(0, $result['updates']);
        $this->assertCount(0, $result['deletes']);
    }

    public function testAggregateChangesWithNoChanges(): void
    {
        $unitOfWork = $this->entityManager->getActiveUnitOfWork();

        $result = $this->aggregator->aggregateChanges([$unitOfWork]);

        $this->assertEquals([
            'inserts' => [],
            'updates' => [],
            'deletes' => [],
            'softDeletes' => [],
        ], $result);
    }

    public function testAggregateChangesWithPersistedEntities(): void
    {
        $unitOfWork = $this->entityManager->getActiveUnitOfWork();

        $entity = new TestEntityForChangeAggregation();
        $entity->id = 1;
        $entity->name = 'Test Entity';

        $this->entityManager->persist($entity);

        $result = $this->aggregator->aggregateChanges([$unitOfWork]);

        $this->assertCount(1, $result['inserts']);
        $this->assertCount(0, $result['updates']);
        $this->assertCount(0, $result['deletes']);
        $this->assertSame($entity, $result['inserts'][0]);
    }

    public function testDeleteWinsOverUpdate(): void
    {
        $metadataRegistry = $this->entityManager->getMetadataRegistry();

        $unitOfWorkUpdate = new UnitOfWork(metadataRegistry: $metadataRegistry);
        $entityToUpdate = new TestEntityForChangeAggregation();
        $entityToUpdate->id = 10;
        $entityToUpdate->name = 'Original';
        $unitOfWorkUpdate->registerManaged($entityToUpdate, ['id' => 10, 'name' => 'Original', 'active' => true]);
        $entityToUpdate->name = 'Updated';

        $unitOfWorkDelete = new UnitOfWork(metadataRegistry: $metadataRegistry);
        $entityToDelete = new TestEntityForChangeAggregation();
        $entityToDelete->id = 10;
        $entityToDelete->name = 'Original';
        $unitOfWorkDelete->registerManaged($entityToDelete, ['id' => 10, 'name' => 'Original', 'active' => true]);
        $unitOfWorkDelete->remove($entityToDelete);

        $result = $this->aggregator->aggregateChanges([$unitOfWorkUpdate, $unitOfWorkDelete]);

        $this->assertCount(0, $result['inserts']);
        $this->assertCount(0, $result['updates']);
        $this->assertCount(1, $result['deletes']);
        $this->assertSame($entityToDelete, $result['deletes'][0]);
    }

    // ── Mutation killers for 167-174 ─────────────────────────────────────────

    public function testEntityIdentityWithoutPrimaryKeyUsesClassAndId(): void
    {
        // TestEntityForChangeAggregation has no #[PrimaryKey] attribute (only
        // #[Property] on 'id'), so getEntityIdentity() falls into the
        // "no primary key columns" branch: `class . ':' . (id ?? spl_object_id)`.
        $metadataRegistry = $this->entityManager->getMetadataRegistry();

        $uow1 = new UnitOfWork(metadataRegistry: $metadataRegistry);
        $entityA = new TestEntityForChangeAggregation();
        $entityA->id = 99;
        $entityA->name = 'A1';
        $uow1->registerManaged($entityA, ['id' => 99, 'name' => 'A0']);
        $entityA->name = 'A2';

        $uow2 = new UnitOfWork(metadataRegistry: $metadataRegistry);
        $entityB = new TestEntityForChangeAggregation();
        $entityB->id = 99;
        $entityB->name = 'B1';
        $uow2->registerManaged($entityB, ['id' => 99, 'name' => 'B0']);
        $entityB->name = 'B2';

        // Two distinct entity instances sharing the same id=99 must merge into
        // ONE update entry — proving the identity string is correctly keyed on
        // "class:id" (ConcatOperandRemoval/Concat mutants would produce a broken
        // or unstable key, e.g. missing the class or the separator, which could
        // either over-merge across classes or under-merge same-id entities).
        $result = $this->aggregator->aggregateChanges([$uow1, $uow2]);

        $this->assertCount(1, $result['updates'], 'Entities with identical class:id identity must merge into one update');
    }

    public function testOverlappingKeyLogMessageIncludesEntityIdentityInContext(): void
    {
        $logger = new ChangeAggregatorTestLogger();
        $metadataRegistry = $this->entityManager->getMetadataRegistry();
        $aggregator = new ChangeAggregator(
            $metadataRegistry,
            $this->entityManager->getUpdateConflictResolutionStrategy(),
            $logger,
        );

        $uow1 = new UnitOfWork(metadataRegistry: $metadataRegistry);
        $entity1 = new TestEntityForChangeAggregation();
        $entity1->id = 5;
        $entity1->name = 'Original';
        $uow1->registerManaged($entity1, ['id' => 5, 'name' => 'Original']);
        $entity1->name = 'FirstChange';

        $uow2 = new UnitOfWork(metadataRegistry: $metadataRegistry);
        $entity2 = new TestEntityForChangeAggregation();
        $entity2->id = 5;
        $entity2->name = 'Original';
        $uow2->registerManaged($entity2, ['id' => 5, 'name' => 'Original']);
        $entity2->name = 'SecondChange';

        $aggregator->aggregateChanges([$uow1, $uow2]);

        $debugLog = null;
        foreach ($logger->records as $record) {
            if ($record['level'] === 'debug' && str_contains($record['message'], 'overlapping')) {
                $debugLog = $record;

                break;
            }
        }

        $this->assertNotNull($debugLog, 'Expected a debug log about overlapping changes');
        // ArrayItemRemoval mutant drops the 'entity' key from the log context — assert it survives.
        $this->assertArrayHasKey('entity', $debugLog['context'], "Log context must include the 'entity' identity key");
        $this->assertSame(TestEntityForChangeAggregation::class . ':5', $debugLog['context']['entity']);
    }

    public function testDeleteDoesNotFilterOutUpdatesFromDifferentEntity(): void
    {
        // optimizeDeletes() filters updates whose identity matches a delete in the
        // SAME class bucket. ReturnRemoval on the closure's `return !in_array(...)`
        // would make array_filter() see a null callback result (falsy), dropping
        // every update for that class regardless of identity match.
        $metadataRegistry = $this->entityManager->getMetadataRegistry();

        $uow = new UnitOfWork(metadataRegistry: $metadataRegistry);

        $toDelete = new TestEntityForChangeAggregation();
        $toDelete->id = 1;
        $toDelete->name = 'Delete Me';
        $uow->registerManaged($toDelete, ['id' => 1, 'name' => 'Delete Me', 'active' => true]);
        $uow->remove($toDelete);

        $toUpdate = new TestEntityForChangeAggregation();
        $toUpdate->id = 2;
        $toUpdate->name = 'Keep Me';
        $uow->registerManaged($toUpdate, ['id' => 2, 'name' => 'Keep Me', 'active' => true]);
        $toUpdate->name = 'Updated Keep Me';

        $result = $this->aggregator->aggregateChanges([$uow]);

        $this->assertCount(1, $result['deletes']);
        $this->assertCount(1, $result['updates'], 'Update for a different entity (different id) must survive delete-filtering');
        $this->assertSame($toUpdate, $result['updates'][0]['entity']);
    }
}

#[Entity]
class TestEntityForChangeAggregation2 {
    #[Property]
    public ?int $id = null;

    #[Property]
    public string $title;
}
