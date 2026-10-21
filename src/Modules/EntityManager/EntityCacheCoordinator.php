<?php

namespace Articulate\Modules\EntityManager;

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
     * @param array{updates: list<EntityUpdate|MergedUpdate>, deletes: object[], softDeletes: object[]} $changes
     */
    public function invalidateSecondLevelCache(array $changes): void
    {
        if ($this->secondLevelCache === null) {
            return;
        }

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
