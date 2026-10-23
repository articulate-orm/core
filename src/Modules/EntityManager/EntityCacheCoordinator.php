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
        int $secondLevelCacheTtl = 3600,
        private readonly CollectionCacheInterface $collectionCache = new NullCollectionCache(),
    ) {
        $secondLevelCachePool = $secondLevelCache ?? $resultCache;
        if ($secondLevelCachePool !== null) {
            $this->secondLevelCache = new SecondLevelCache($secondLevelCachePool, $secondLevelCacheTtl);
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
     * @param array{updates: array<int, array{entity?: object}>, deletes: object[], softDeletes: object[]} $changes
     */
    public function invalidateSecondLevelCache(array $changes): void
    {
        foreach ($changes['updates'] as $update) {
            if (isset($update['entity'])) {
                $this->evictEntityFromSecondLevelCache($update['entity']);
            }
        }

        foreach ($changes['deletes'] as $entity) {
            $this->evictEntityFromSecondLevelCache($entity);
        }

        foreach ($changes['softDeletes'] as $entity) {
            $this->evictEntityFromSecondLevelCache($entity);
        }
    }

    public function getSecondLevelCacheData(string $class, mixed $id): ?array
    {
        return $this->secondLevelCache?->get($class, $id);
    }

    public function setSecondLevelCacheData(string $class, mixed $id, array $data): void
    {
        $this->secondLevelCache?->set($class, $id, $data);
    }

    private function evictEntityFromSecondLevelCache(object $entity): void
    {
        $entityClass = $entity instanceof Proxy\ProxyInterface
            ? $entity->getProxyEntityClass()
            : $entity::class;
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
