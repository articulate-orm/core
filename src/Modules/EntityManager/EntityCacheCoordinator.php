<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Modules\Cache\CollectionCacheInterface;
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
     * @param array{updates: array<int, array{entity?: object}>, deletes: object[], softDeletes: object[]} $changes
     */
    public function invalidateSecondLevelCache(array $changes): void
    {
        if ($this->secondLevelCache === null) {
            return;
        }

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

            return;
        }

        foreach ($siblingClasses as $class) {
            $this->secondLevelCache?->evict($class, $id);
        }
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
