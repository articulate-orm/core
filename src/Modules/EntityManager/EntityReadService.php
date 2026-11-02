<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Connection;
use Articulate\Modules\Cache\CollectionCache;
use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\Cache\NullCollectionCache;
use Articulate\Modules\QueryBuilder\Filter\FilterCollection;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Schema\CachedEntityResolverInterface;
use Articulate\Schema\EntityMetadata;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;

class EntityReadService implements CachedEntityResolverInterface {
    public function __construct(
        private readonly Connection $connection,
        private HydratorInterface $hydrator,
        private readonly EntityMetadataRegistry $metadataRegistry,
        private readonly UnitOfWorkRegistry $unitOfWorkRegistry,
        private readonly EntityCacheCoordinator $cacheCoordinator,
        private readonly FilterCollection $filters,
        private readonly ?CacheItemPoolInterface $resultCache,
        private readonly ?CacheItemPoolInterface $statementCache,
        private ?Proxy\ProxyManager $proxyManager,
        private readonly ?CacheItemPoolInterface $secondLevelCache = null,
        private readonly int $secondLevelCacheTtl = CollectionCacheInterface::DEFAULT_TTL,
    ) {
    }

    public function setHydrator(HydratorInterface $hydrator): void
    {
        $this->hydrator = $hydrator;
    }

    public function setProxyManager(Proxy\ProxyManager $proxyManager): void
    {
        $this->proxyManager = $proxyManager;
    }

    /**
     * @param string[] $with Relation property names to force-eager even when lazy: true
     */
    public function find(string $class, mixed $id, array $with = []): ?object
    {
        if (!empty($with)) {
            $this->validateWith($class, $with);
        }

        foreach ($this->unitOfWorkRegistry->all() as $unitOfWork) {
            $entity = $unitOfWork->tryGetById($class, $id);
            if ($entity) {
                return $entity;
            }
        }

        $cachedData = $this->cacheCoordinator->getSecondLevelCacheData($class, $id);
        if ($cachedData !== null) {
            $entity = $this->hydrator->hydrate($class, $cachedData, null, $with);
            $this->unitOfWorkRegistry->active()->registerManaged($entity, $cachedData);

            return $entity;
        }

        $metadata = $this->metadataRegistry->getMetadata($class);
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();

        if (empty($primaryKeyColumns)) {
            return null;
        }

        $qb = $this->createQueryBuilder($class)
            ->select(...$this->getSelectColumnsForEntity($metadata))
            ->from($metadata->getTableName())
            ->where($primaryKeyColumns[0], $id)
            ->limit(1);

        $statement = $this->connection->executeQuery($qb->getSQL(), $qb->getParameters());
        $rawResults = $statement->fetchAll();

        if (empty($rawResults)) {
            return null;
        }

        $rawData = $rawResults[0];
        $this->cacheCoordinator->setSecondLevelCacheData($class, $id, $rawData);

        $entity = $this->hydrator->hydrate($class, $rawData, null, $with);
        $this->unitOfWorkRegistry->active()->registerManaged($entity, $rawData);

        return $entity;
    }

    /**
     * @param string[] $with Relation property names to force-eager even when lazy: true
     * @return object[]
     */
    public function findAll(string $class, array $with = []): array
    {
        if (!empty($with)) {
            $this->validateWith($class, $with);
        }

        $metadata = $this->metadataRegistry->getMetadata($class);

        $qb = $this->createQueryBuilder($class)
            ->select(...$this->getSelectColumnsForEntity($metadata))
            ->from($metadata->getTableName());

        $statement = $this->connection->executeQuery($qb->getSQL(), $qb->getParameters());
        $rawResults = $statement->fetchAll();

        if (empty($rawResults)) {
            return [];
        }

        $entities = [];
        foreach ($rawResults as $rawData) {
            $entity = $this->hydrator->hydrate($class, $rawData, null, $with);
            $this->unitOfWorkRegistry->active()->registerManaged($entity, $rawData);
            $entities[] = $entity;
        }

        return $entities;
    }

    public function createQueryBuilder(?string $entityClass = null): QueryBuilder
    {
        $collectionCache = $this->secondLevelCache !== null
            ? new CollectionCache($this->secondLevelCache)
            : new NullCollectionCache();

        $qb = new QueryBuilder(
            $this->connection,
            $this->hydrator,
            $this->metadataRegistry,
            $this->resultCache,
            $this->filters,
            $this->statementCache,
            $collectionCache,
            $this,
            $this->secondLevelCacheTtl,
        );
        $qb->setManagedEntityStore($this->unitOfWorkRegistry);
        $qb->setResultCacheGeneration($this->cacheCoordinator->readQueryCacheGeneration());

        if ($entityClass) {
            $qb->setEntityClass($entityClass);
        }

        return $qb;
    }

    /**
     * Resolve a primary key to a managed entity purely from in-memory state
     * (identity map) or the per-entity second-level cache — never the
     * database. Used by QueryBuilder to rehydrate a `cacheable()` query's
     * cached id-list. Returning null means the id could not be resolved this
     * way; the caller (QueryBuilder) treats the entire cached list as a miss.
     */
    public function tryResolve(string $class, mixed $id): ?object
    {
        foreach ($this->unitOfWorkRegistry->all() as $unitOfWork) {
            $entity = $unitOfWork->tryGetById($class, $id);
            if ($entity !== null) {
                return $entity;
            }
        }

        $cachedData = $this->cacheCoordinator->getSecondLevelCacheData($class, $id);
        if ($cachedData === null) {
            return null;
        }

        $entity = $this->hydrator->hydrate($class, $cachedData);
        $this->unitOfWorkRegistry->active()->registerManaged($entity, $cachedData);

        return $entity;
    }

    public function tryResolveMany(string $class, array $ids): ?array
    {
        $entities = [];
        $unresolved = [];
        foreach ($ids as $index => $id) {
            $entity = $this->findInIdentityMaps($class, $id);
            if ($entity === null) {
                $unresolved[$index] = $id;

                continue;
            }

            $entities[$index] = $entity;
        }

        if ($unresolved !== []) {
            $cachedRows = $this->cacheCoordinator->getSecondLevelCacheDataMany($class, $unresolved);
            if (in_array(null, $cachedRows, true)) {
                return null;
            }

            foreach ($cachedRows as $index => $cachedData) {
                $entity = $this->hydrator->hydrate($class, $cachedData);
                $this->unitOfWorkRegistry->active()->registerManaged($entity, $cachedData);
                $entities[$index] = $entity;
            }
        }

        ksort($entities);

        return array_values($entities);
    }

    public function cacheResolvedRows(string $class, array $idRowPairs): void
    {
        $this->cacheCoordinator->setSecondLevelCacheDataMany($class, $idRowPairs);
    }

    private function findInIdentityMaps(string $class, mixed $id): ?object
    {
        foreach ($this->unitOfWorkRegistry->all() as $unitOfWork) {
            $entity = $unitOfWork->tryGetById($class, $id);
            if ($entity !== null) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rawData
     */
    public function cacheResolvedRow(string $class, mixed $id, array $rawData): void
    {
        $this->cacheCoordinator->setSecondLevelCacheData($class, $id, $rawData);
    }

    public function createLazyReference(string $class, \Closure $loader): object
    {
        if ($this->proxyManager === null) {
            throw new \RuntimeException('Proxy manager is not available');
        }

        return $this->proxyManager->createProxyWithCustomLoader($class, $loader);
    }

    public function getReference(string $class, mixed $id): object
    {
        foreach ($this->unitOfWorkRegistry->all() as $unitOfWork) {
            $entity = $unitOfWork->tryGetById($class, $id);
            if ($entity) {
                return $entity;
            }
        }

        if ($this->proxyManager === null) {
            throw new \RuntimeException('Proxy manager is not available');
        }

        $proxy = $this->proxyManager->createProxy($class, $id);
        $this->unitOfWorkRegistry->active()->registerManaged($proxy, []);

        return $proxy;
    }

    /**
     * @param string[] $with
     */
    public function validateWith(string $class, array $with): void
    {
        $metadata = $this->metadataRegistry->getMetadata($class);
        $knownRelations = array_keys($metadata->getRelations());

        foreach ($with as $name) {
            if (!in_array($name, $knownRelations, true)) {
                throw new InvalidArgumentException(
                    "Relation '$name' not found on entity $class. Known relations: " . implode(', ', $knownRelations)
                );
            }
        }
    }

    /**
     * @return string[]
     */
    public function getSelectColumnsForEntity(EntityMetadata $metadata): array
    {
        $columnNames = $metadata->getColumnNames();

        foreach ($metadata->getColumnRelations() as $relation) {
            if ($relation->isMorphTo()) {
                $columnNames[] = $relation->getMorphTypeColumnName();
                $columnNames[] = $relation->getMorphIdColumnName();
            } elseif (!$relation->isLazy() && $relation->isOwningSide() && $relation->getColumnName() !== null && !in_array($relation->getColumnName(), $columnNames, true)) {
                // Include FK column for eager owning-side relations so Fallback 1 in getForeignKeyValue
                // can resolve the FK from the already-fetched row without an extra SELECT.
                $columnNames[] = $relation->getColumnName();
            }
        }

        return $columnNames;
    }
}
