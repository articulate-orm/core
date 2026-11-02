<?php

namespace Articulate\Tests\Schema;

use Articulate\Attributes\Reflection\ReflectionManyToMany;
use Articulate\Schema\EntityMetadata;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestEntity;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestManyToManyOwner;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestSecondEntity;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class MetadataCacheItem implements CacheItemInterface {
    private mixed $value = null;

    private bool $isHit = false;

    public function __construct(private readonly string $key)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->isHit = true;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        return $this;
    }
}

class MetadataArrayCache implements CacheItemPoolInterface {
    /** @var array<string, CacheItemInterface> */
    public array $items = [];

    public int $getItemCalls = 0;

    public int $saveCalls = 0;

    public function getItem(string $key): CacheItemInterface
    {
        $this->getItemCalls++;

        return $this->items[$key] ??= new MetadataCacheItem($key);
    }

    /** @return iterable<string, CacheItemInterface> */
    public function getItems(array $keys = []): iterable
    {
        return array_combine($keys, array_map($this->getItem(...), $keys));
    }

    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]) && $this->items[$key]->isHit();
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->saveCalls++;
        $this->items[$item->getKey()] = $item;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

class EntityMetadataRegistryTest extends TestCase {
    public function testGetMetadataReturnsMetadata(): void
    {
        $registry = new EntityMetadataRegistry();

        $metadata = $registry->getMetadata(TestEntity::class);

        $this->assertInstanceOf(EntityMetadata::class, $metadata);
    }

    public function testGetMetadataCachesResult(): void
    {
        $registry = new EntityMetadataRegistry();

        $first = $registry->getMetadata(TestEntity::class);
        $second = $registry->getMetadata(TestEntity::class);

        $this->assertSame($first, $second);
    }

    public function testHasMetadata(): void
    {
        $registry = new EntityMetadataRegistry();

        $this->assertFalse($registry->hasMetadata(TestEntity::class));

        $registry->getMetadata(TestEntity::class);

        $this->assertTrue($registry->hasMetadata(TestEntity::class));
    }

    public function testClearMetadata(): void
    {
        $registry = new EntityMetadataRegistry();

        $registry->getMetadata(TestEntity::class);
        $this->assertTrue($registry->hasMetadata(TestEntity::class));

        $registry->clearMetadata(TestEntity::class);
        $this->assertFalse($registry->hasMetadata(TestEntity::class));
    }

    public function testGetTableName(): void
    {
        $registry = new EntityMetadataRegistry();

        $tableName = $registry->getTableName(TestSecondEntity::class);

        $this->assertEquals('test_entity', $tableName);
    }

    public function testGetMetadataPopulatesCacheOnMiss(): void
    {
        $cache = new MetadataArrayCache();
        $registry = new EntityMetadataRegistry($cache);

        $registry->getMetadata(TestEntity::class);

        $this->assertSame(1, $cache->saveCalls);
    }

    public function testGetMetadataReusesCachedEntryAcrossRegistries(): void
    {
        $cache = new MetadataArrayCache();

        (new EntityMetadataRegistry($cache))->getMetadata(TestEntity::class);
        $this->assertSame(1, $cache->saveCalls);

        // A fresh registry (simulating a new process) must read from the pool, not recompute.
        $second = new EntityMetadataRegistry($cache);
        $metadata = $second->getMetadata(TestEntity::class);

        $this->assertSame(1, $cache->saveCalls);
        $this->assertInstanceOf(EntityMetadata::class, $metadata);
        $this->assertSame('test_entity', $metadata->getTableName());
    }

    public function testClearMetadataEvictsFromCache(): void
    {
        $cache = new MetadataArrayCache();
        $registry = new EntityMetadataRegistry($cache);

        $registry->getMetadata(TestEntity::class);
        $this->assertTrue($cache->hasItem('metadata_' . str_replace('\\', '_', TestEntity::class)));

        $registry->clearMetadata(TestEntity::class);

        $this->assertFalse($cache->hasItem('metadata_' . str_replace('\\', '_', TestEntity::class)));
    }

    public function testClearAllClearsCache(): void
    {
        $cache = new MetadataArrayCache();
        $registry = new EntityMetadataRegistry($cache);

        $registry->getMetadata(TestEntity::class);
        $registry->clearAll();

        $this->assertSame([], $cache->items);
    }

    public function testEntityMetadataSurvivesNativeSerializationRoundTrip(): void
    {
        $original = new EntityMetadata(TestEntity::class);

        /** @var EntityMetadata $restored */
        $restored = unserialize(serialize($original));

        $this->assertSame($original->getTableName(), $restored->getTableName());
        $this->assertSame($original->getClassName(), $restored->getClassName());
        $this->assertSame($original->getPrimaryKeyColumns(), $restored->getPrimaryKeyColumns());
        $this->assertSame(array_keys($original->getProperties()), array_keys($restored->getProperties()));
        $this->assertSame($original->getColumnName('id'), $restored->getColumnName('id'));
    }

    public function testEntityMetadataWithRelationsSurvivesSerializationRoundTrip(): void
    {
        $original = new EntityMetadata(TestManyToManyOwner::class);

        /** @var EntityMetadata $restored */
        $restored = unserialize(serialize($original));

        $this->assertSame($original->getTableName(), $restored->getTableName());
        $this->assertSame(array_keys($original->getRelations()), array_keys($restored->getRelations()));

        foreach ($original->getRelations() as $name => $relation) {
            $restoredRelation = $restored->getRelation($name);
            $this->assertSame($relation->getTargetEntity(), $restoredRelation->getTargetEntity());
            $this->assertInstanceOf(ReflectionManyToMany::class, $relation);
            $this->assertInstanceOf(ReflectionManyToMany::class, $restoredRelation);
            $this->assertSame($relation->getPivotTableName(), $restoredRelation->getPivotTableName());
        }
    }

    public function testClearMetadataOnlyRemovesTheClearedClassFromSharedTableIndex(): void
    {
        // TestEntity and TestSecondEntity both map to the 'test_entity' table
        // (per testGetTableName above). Clearing one must keep the other in the
        // table index — array_filter() with "!==" must survive, not become a
        // same-value filter (NotIdentical mutant) or a passthrough (UnwrapArrayFilter).
        $registry = new EntityMetadataRegistry();

        $registry->getMetadata(TestEntity::class);
        $registry->getMetadata(TestSecondEntity::class);

        $registry->clearMetadata(TestEntity::class);

        $this->assertFalse($registry->hasMetadata(TestEntity::class));
        $this->assertTrue($registry->hasMetadata(TestSecondEntity::class));
        $this->assertSame(
            [TestSecondEntity::class],
            $registry->getClassesByTable('test_entity'),
        );
    }

    public function testClearAllWorksWithoutCacheConfigured(): void
    {
        // Covers NullSafeMethodCall mutants on $this->cache?->clear() /
        // ?->deleteItem(): with no cache pool, these must be safely skipped,
        // not throw on a call to a method on null.
        $registry = new EntityMetadataRegistry();

        $registry->getMetadata(TestEntity::class);
        $registry->clearAll();

        $this->assertFalse($registry->hasMetadata(TestEntity::class));
        $this->assertSame([], $registry->getClassesByTable('test_entity'));
    }

    public function testClearMetadataWorksWithoutCacheConfigured(): void
    {
        $registry = new EntityMetadataRegistry();

        $registry->getMetadata(TestEntity::class);
        $registry->clearMetadata(TestEntity::class);

        $this->assertFalse($registry->hasMetadata(TestEntity::class));
    }
}
