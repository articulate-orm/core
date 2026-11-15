<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\Cache\NullCollectionCache;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Utils\ReflectionCache;
use Psr\Cache\CacheItemPoolInterface;

class EntityCacheCoordinator {
    private ?SecondLevelCache $secondLevelCache = null;

    public function __construct(
        private readonly ?CacheItemPoolInterface $resultCache,
        private readonly EntityMetadataRegistry $metadataRegistry,
        ?CacheItemPoolInterface $secondLevelCache = null,
        int $secondLevelCacheTtl = CollectionCacheInterface::DEFAULT_TTL,
        string $cacheNamespace = '',
        private readonly CollectionCacheInterface $collectionCache = new NullCollectionCache(),
    ) {
        $secondLevelCachePool = $secondLevelCache ?? $resultCache;
        if ($secondLevelCachePool !== null) {
            $this->secondLevelCache = new SecondLevelCache($secondLevelCachePool, $secondLevelCacheTtl, $cacheNamespace);
        }
    }

    public function readQueryCacheGeneration(): int
    {
        if ($this->resultCache === null) {
            return 0;
        }

        try {
            $item = $this->resultCache->getItem('articulate_qrc_gen');

            return $item->isHit() ? (int) $item->get() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function incrementQueryCacheGeneration(): void
    {
        if ($this->resultCache === null) {
            return;
        }

        try {
            $item = $this->resultCache->getItem('articulate_qrc_gen');
            $item->set($item->isHit() ? (int) $item->get() + 1 : 1);
            $this->resultCache->save($item);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array{inserts: object[], updates: list<EntityUpdate|MergedUpdate>, deletes: object[], softDeletes: object[]} $changes
     */
    public function invalidateSecondLevelCache(array $changes): void
    {
        if ($this->secondLevelCache === null && $this->collectionCache instanceof NullCollectionCache) {
            return;
        }

        $this->bumpInsertedTableGenerations($changes['inserts']);

        foreach ($changes['updates'] as $update) {
            if ($update->entity !== null) {
                $this->evictEntityFromSecondLevelCache($update->entity);
            }
        }

        foreach ($changes['deletes'] as $entity) {
            $this->evictEntityFromSecondLevelCache($entity);
        }

        foreach ($changes['softDeletes'] as $entity) {
            $this->evictEntityFromSecondLevelCache($entity);
        }
    }

    public function getCollectionCache(): CollectionCacheInterface
    {
        return $this->collectionCache;
    }

    public function getSecondLevelCacheData(string $class, mixed $id): ?array
    {
        return $this->secondLevelCache?->get($class, $id);
    }

    public function setSecondLevelCacheData(string $class, mixed $id, array $data): void
    {
        $this->secondLevelCache?->set($class, $id, $data);
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, array<string, mixed>|null>
     */
    public function getSecondLevelCacheDataMany(string $class, array $ids): array
    {
        return $this->secondLevelCache?->getMany($class, $ids) ?? array_fill_keys(array_keys($ids), null);
    }

    /**
     * @param list<array{0: mixed, 1: array<string, mixed>}> $idRowPairs
     */
    public function setSecondLevelCacheDataMany(string $class, array $idRowPairs): void
    {
        $this->secondLevelCache?->setMany($class, $idRowPairs);
    }

    /**
     * Inserts can't be matched against cached WHERE clauses (ADR-0002, case
     * (b)), so each table that received at least one insert gets exactly one
     * generation bump per flush, however many rows went into it.
     *
     * @param object[] $inserts
     */
    private function bumpInsertedTableGenerations(array $inserts): void
    {
        $tableNames = [];
        foreach ($inserts as $entity) {
            $tableNames[$this->metadataRegistry->getTableName($this->resolveEntityClass($entity))] = true;
        }

        foreach (array_keys($tableNames) as $tableName) {
            $this->collectionCache->incrementTableGeneration((string) $tableName);
        }
    }

    private function evictEntityFromSecondLevelCache(object $entity): void
    {
        $entityClass = $this->resolveEntityClass($entity);
        $id = $this->extractEntityIdForCache($entity, $entityClass);

        if ($id === null) {
            return;
        }

        $tableName = $this->metadataRegistry->getTableName($entityClass);
        $siblingClasses = $this->metadataRegistry->getClassesByTable($tableName);

        if (empty($siblingClasses)) {
            $this->secondLevelCache?->evict($entityClass, $id);
        } else {
            foreach ($siblingClasses as $class) {
                $this->secondLevelCache?->evict($class, $id);
            }
        }

        $this->evictReverseIndexedQueryCacheEntries($tableName, $id);
    }

    /**
     * Exact invalidation (ADR-0002): resolve the table + id for the
     * changed row, look up the reverse index for that pair, and evict
     * exactly the query-cache entries it names — never a blunt "evict
     * everything for this table" sweep. Table-scoped so sibling classes
     * sharing a table/PK resolve to the same entry, and unrelated tables
     * with a coincidentally-matching raw id never collide. Normalization
     * of $id happens inside the CollectionCacheInterface implementation
     * (same as SecondLevelCache), so the raw id is passed through as-is.
     */
    private function evictReverseIndexedQueryCacheEntries(string $tableName, mixed $id): void
    {
        $cacheKeys = $this->collectionCache->getReverseIndexCacheKeys($tableName, $id);

        if ($cacheKeys === null) {
            return;
        }

        foreach ($cacheKeys as $cacheKey) {
            $this->collectionCache->evictIdList($cacheKey);
        }

        $this->collectionCache->evictReverseIndex($tableName, $id);
    }

    private function resolveEntityClass(object $entity): string
    {
        return $entity instanceof Proxy\ProxyInterface ? $entity->getProxyEntityClass() : $entity::class;
    }

    private function extractEntityIdForCache(object $entity, string $entityClass): mixed
    {
        if ($entity instanceof Proxy\ProxyInterface) {
            return $entity->getProxyIdentifier();
        }

        $metadata = $this->metadataRegistry->getMetadata($entityClass);
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();

        if (empty($primaryKeyColumns)) {
            return null;
        }

        $propertyName = $metadata->getPropertyNameForColumn($primaryKeyColumns[0]);

        if ($propertyName === null) {
            return null;
        }

        $reflectionProperty = ReflectionCache::getProperty($entity::class, $propertyName);
        $reflectionProperty->setAccessible(true);

        return $reflectionProperty->getValue($entity);
    }
}
