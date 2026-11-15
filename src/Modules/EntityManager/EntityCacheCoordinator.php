<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\Cache\NullCollectionCache;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Utils\ReflectionCache;
use Articulate\Utils\TypeRegistry;
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
        private readonly TypeRegistry $typeRegistry = new TypeRegistry(),
    ) {
        if ($secondLevelCache !== null) {
            $this->secondLevelCache = new SecondLevelCache($secondLevelCache, $secondLevelCacheTtl, $cacheNamespace);
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
        return $this->secondLevelCache?->get($class, $this->canonicalId($class, $id));
    }

    public function setSecondLevelCacheData(string $class, mixed $id, array $data): void
    {
        $this->secondLevelCache?->set($class, $this->canonicalId($class, $id), $data);
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, array<string, mixed>|null>
     */
    public function getSecondLevelCacheDataMany(string $class, array $ids): array
    {
        if ($this->secondLevelCache === null) {
            return array_fill_keys(array_keys($ids), null);
        }

        return $this->secondLevelCache->getMany($class, array_map(fn (mixed $id): mixed => $this->canonicalId($class, $id), $ids));
    }

    /**
     * @param list<array{0: mixed, 1: array<string, mixed>}> $idRowPairs
     */
    public function setSecondLevelCacheDataMany(string $class, array $idRowPairs): void
    {
        $this->secondLevelCache?->setMany(
            $class,
            array_map(fn (array $pair): array => [$this->canonicalId($class, $pair[0]), $pair[1]], $idRowPairs),
        );
    }

    /**
     * Ids reach the cache in whatever form the caller holds — a route-param
     * string for find(), a raw DB value for freshly loaded rows, the typed
     * property for flush eviction. The type-tagged key would treat "1" and 1
     * as different entries, so every path is first brought to the primary-key
     * property's PHP representation, using the same conversion as hydration.
     */
    private function canonicalId(string $class, mixed $id): mixed
    {
        if (is_object($id) || is_array($id) || $id === null) {
            return $id;
        }

        try {
            $type = $this->primaryKeyType($class);
            if ($type === null) {
                return $id;
            }

            $converter = $this->typeRegistry->getConverter($type);
            if ($converter !== null) {
                return $converter->convertToPHP($id);
            }

            return match (ltrim($type, '?')) {
                'int' => is_string($id) && preg_match('/^-?\d+$/', $id) === 1 ? (int) $id : $id,
                'string' => is_int($id) ? (string) $id : $id,
                default => $id,
            };
        } catch (\Throwable) {
            return $id;
        }
    }

    private function primaryKeyType(string $class): ?string
    {
        $metadata = $this->metadataRegistry->getMetadata($class);
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();
        if (count($primaryKeyColumns) !== 1) {
            return null;
        }

        $propertyName = $metadata->getPropertyNameForColumn($primaryKeyColumns[0]);

        return $propertyName === null ? null : $metadata->getProperty($propertyName)?->getType();
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

        foreach (empty($siblingClasses) ? [$entityClass] : $siblingClasses as $class) {
            $this->secondLevelCache?->evict($class, $this->canonicalId($class, $id));
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
