<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\EntityManager\MergeUpdateConflictResolutionStrategy;
use PHPUnit\Framework\TestCase;

#[Entity]
class MergeConflictEntity {
    #[Property]
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name = '';

    #[Property]
    public string $status = '';
}

/**
 * Entity with a plain 'id' property but NO #[PrimaryKey] attribute.
 * This exercises the fallback path in buildUpdateIdentity() (lines 135-148)
 * where getPrimaryKeyColumns() returns [] and the method falls back to looking
 * up the property named 'id' via getProperty('id').
 */
#[Entity]
class MergeNoKeyEntity {
    #[Property]
    public ?int $id = null;

    #[Property]
    public string $name = '';
}

class MergeUpdateConflictResolutionStrategyTest extends TestCase {
    private EntityManager $entityManager;

    private MergeUpdateConflictResolutionStrategy $strategy;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $this->entityManager = new EntityManager($connection);
        $this->strategy = new MergeUpdateConflictResolutionStrategy();
    }

    public function testPassesThroughUpdateWhenEntityHasNullPrimaryKey(): void
    {
        $entity = new MergeConflictEntity();

        $update = ['entity' => $entity, 'changes' => ['name' => 'New Name']];

        $result = $this->strategy->resolve([$update], $this->entityManager->getMetadataRegistry());

        $this->assertSame([$update], $result);
    }

    public function testMergesMultipleUpdatesForSameEntityIntoOneResult(): void
    {
        $entity = new MergeConflictEntity();
        $entity->id = 1;

        $updates = [
            ['entity' => $entity, 'changes' => ['name' => 'New Name']],
            ['entity' => $entity, 'changes' => ['status' => 'active']],
        ];

        $result = $this->strategy->resolve($updates, $this->entityManager->getMetadataRegistry());

        $this->assertCount(1, $result);
        $this->assertSame('New Name', $result[0]['set']['name']);
        $this->assertSame('active', $result[0]['set']['status']);
    }

    public function testLastWriteWinsForSameColumnConflict(): void
    {
        $entity = new MergeConflictEntity();
        $entity->id = 1;

        $updates = [
            ['entity' => $entity, 'changes' => ['name' => 'First']],
            ['entity' => $entity, 'changes' => ['name' => 'Second']],
        ];

        $result = $this->strategy->resolve($updates, $this->entityManager->getMetadataRegistry());

        $this->assertCount(1, $result);
        $this->assertSame('Second', $result[0]['set']['name']);
    }

    public function testKeepsSeparateUpdatesForEntitiesWithDifferentIdentities(): void
    {
        $entityOne = new MergeConflictEntity();
        $entityOne->id = 1;

        $entityTwo = new MergeConflictEntity();
        $entityTwo->id = 2;

        $updates = [
            ['entity' => $entityOne, 'changes' => ['name' => 'One']],
            ['entity' => $entityTwo, 'changes' => ['name' => 'Two']],
        ];

        $result = $this->strategy->resolve($updates, $this->entityManager->getMetadataRegistry());

        $this->assertCount(2, $result);
    }

    public function testMergedResultContainsWhereClauseWithPrimaryKeyValue(): void
    {
        $entity = new MergeConflictEntity();
        $entity->id = 42;

        $result = $this->strategy->resolve(
            [['entity' => $entity, 'changes' => ['name' => 'Test']]],
            $this->entityManager->getMetadataRegistry()
        );

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('table', $result[0]);
        $this->assertArrayHasKey('set', $result[0]);
        $this->assertStringContainsString('id', $result[0]['where']);
        $this->assertContains(42, $result[0]['whereValues']);
    }

    // ── Fallback id-property path mutation killers ────────

    public function testFallbackPathMergesEntityWithPlainIdProperty(): void
    {
        $entity = new MergeNoKeyEntity();
        $entity->id = 42;

        $result = $this->strategy->resolve(
            [['entity' => $entity, 'changes' => ['name' => 'Test']]],
            $this->entityManager->getMetadataRegistry(),
        );

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('table', $result[0], 'Entity with plain id must be merged into a table-scoped update');
        $this->assertContains(42, $result[0]['whereValues']);
    }

    public function testFallbackPathPassesThroughEntityWithNullPlainId(): void
    {
        $entity = new MergeNoKeyEntity();
        // id stays null

        $result = $this->strategy->resolve(
            [['entity' => $entity, 'changes' => ['name' => 'Test']]],
            $this->entityManager->getMetadataRegistry(),
        );

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('entity', $result[0], 'Entity with null id must pass through unmerged');
    }
}
