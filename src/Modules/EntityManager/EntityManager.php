<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Connection;
use Articulate\Modules\Generators\GeneratorRegistry;
use Articulate\Modules\QueryBuilder\Filter\FilterCollection;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use Articulate\Utils\TypeRegistry;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

class EntityManager {
    private Connection $connection;

    private HydratorInterface $hydrator;

    private FilterCollection $filters;

    private GeneratorRegistry $generatorRegistry;

    private EntityMetadataRegistry $metadataRegistry;

    private LifecycleCallbackManager $callbackManager;

    private ChangeAggregator $changeAggregator;

    private ?Proxy\ProxyManager $proxyManager = null;

    private ?RepositoryFactoryInterface $repositoryFactory = null;

    private UpdateConflictResolutionStrategy $updateConflictResolutionStrategy;

    private RelationshipLoader $relationshipLoader;

    private UnitOfWorkRegistry $unitOfWorkRegistry;

    private EntityCacheCoordinator $cacheCoordinator;

    private EntityDependencySorter $dependencySorter;

    private ChangeSetExecutor $changeSetExecutor;

    private EntityReadService $readService;

    private EntityRefreshService $refreshService;

    public function __construct(
        Connection $connection,
        ?ChangeTrackingStrategy $changeTrackingStrategy = null,
        ?HydratorInterface $hydrator = null,
        ?GeneratorRegistry $generatorRegistry = null,
        ?EntityMetadataRegistry $metadataRegistry = null,
        ?QueryExecutor $queryExecutor = null,
        ?UpdateConflictResolutionStrategy $updateConflictResolutionStrategy = null,
        ?CacheItemPoolInterface $resultCache = null,
        ?RepositoryFactoryInterface $repositoryFactory = null,
        ?CacheItemPoolInterface $statementCache = null,
        ?CacheItemPoolInterface $secondLevelCache = null,
        int $secondLevelCacheTtl = 3600,
        ?LoggerInterface $logger = null,
    ) {
        $this->connection = $connection;
        $this->generatorRegistry = $generatorRegistry ?? new GeneratorRegistry();
        $this->metadataRegistry = $metadataRegistry ?? new EntityMetadataRegistry();

        // Create default change tracking strategy with metadata registry
        $usesDefaultChangeTrackingStrategy = $changeTrackingStrategy === null;
        $changeTrackingStrategy = $changeTrackingStrategy ?? new DeferredImplicitStrategy($this->metadataRegistry);

        // Initialize callback manager
        $this->callbackManager = new LifecycleCallbackManager();

        // Initialize change aggregator and query executor
        $this->updateConflictResolutionStrategy = $updateConflictResolutionStrategy ?? new MergeUpdateConflictResolutionStrategy();
        $this->changeAggregator = new ChangeAggregator($this->metadataRegistry, $this->updateConflictResolutionStrategy, $logger);
        $queryExecutor = $queryExecutor ?? new QueryExecutor($this->connection, $this->generatorRegistry);

        $this->unitOfWorkRegistry = new UnitOfWorkRegistry(
            $changeTrackingStrategy,
            $usesDefaultChangeTrackingStrategy,
            $this->callbackManager,
            $this->metadataRegistry,
        );

        $this->relationshipLoader = new RelationshipLoader($this, $this->metadataRegistry);

        // Initialize proxy system
        $proxyGenerator = new Proxy\ProxyGenerator($this->metadataRegistry);
        $this->proxyManager = new Proxy\ProxyManager(
            $this,
            $proxyGenerator
        );

        $typeRegistry = new TypeRegistry();

        $this->hydrator = $hydrator ?? new ObjectHydrator(new ActiveUnitOfWorkRegistrar($this), $this->relationshipLoader, $this->callbackManager, $typeRegistry);

        $this->repositoryFactory = $repositoryFactory;

        $this->filters = new FilterCollection();
        $this->cacheCoordinator = new EntityCacheCoordinator($resultCache, $this->metadataRegistry, $secondLevelCache, $secondLevelCacheTtl, $this->connection->getCacheNamespace());
        $this->dependencySorter = new EntityDependencySorter($this->metadataRegistry);
        $this->changeSetExecutor = new ChangeSetExecutor($queryExecutor, $this->metadataRegistry, $this->dependencySorter);
        $this->readService = new EntityReadService(
            $this->connection,
            $this->hydrator,
            $this->metadataRegistry,
            $this->unitOfWorkRegistry,
            $this->cacheCoordinator,
            $this->filters,
            $resultCache,
            $statementCache,
            $this->proxyManager,
            $secondLevelCache,
            $secondLevelCacheTtl,
        );
        $this->refreshService = new EntityRefreshService(
            $this->connection,
            $this->hydrator,
            $this->metadataRegistry,
            $this->unitOfWorkRegistry,
            $this->readService,
            $typeRegistry,
        );
    }

    // Persistence operations
    public function persist(object $entity): void
    {
        $this->unitOfWorkRegistry->active()->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->unitOfWorkRegistry->active()->remove($entity);
    }

    public function flush(): void
    {
        $managingTransaction = !$this->connection->inTransaction();

        if ($managingTransaction) {
            $this->connection->beginTransaction();
        }

        /** @var list<DeferredVersionBump> $deferredVersionBumps */
        $deferredVersionBumps = [];

        try {
            $unitOfWorks = $this->unitOfWorkRegistry->all();
            foreach ($unitOfWorks as $unitOfWork) {
                $unitOfWork->assertNoInvalidReferences(
                    fn (object $entity): EntityState => $this->unitOfWorkRegistry->getEntityState($entity)
                );
            }

            $aggregatedChanges = $this->changeAggregator->aggregateChanges($unitOfWorks);
            $deferredVersionBumps = $this->changeSetExecutor->execute($aggregatedChanges);
            $this->changeSetExecutor->syncManagedManyToMany($unitOfWorks);
            $this->cacheCoordinator->invalidateSecondLevelCache($aggregatedChanges);
            $this->cacheCoordinator->incrementQueryCacheGeneration();

            // Bump the in-memory #[Version] properties before post-update callbacks so a
            // #[PostUpdate] handler sees the value the row now carries. The catch block
            // reverts them if the flush never commits, so a mid-flush conflict still
            // can't strand a stale version and poison later retries.
            foreach ($deferredVersionBumps as $bump) {
                $bump->apply();
            }

            foreach ($unitOfWorks as $unitOfWork) {
                $unitOfWork->executePostCallbacks($unitOfWork->getChangeSets());
            }

            if ($managingTransaction) {
                $this->connection->commit();
            }

            foreach ($unitOfWorks as $unitOfWork) {
                $unitOfWork->clearChanges();
            }
        } catch (\Throwable $e) {
            foreach ($deferredVersionBumps as $bump) {
                $bump->revert();
            }

            if ($managingTransaction) {
                $this->connection->rollbackTransaction();
            }

            throw $e;
        }
    }

    public function clear(): void
    {
        $this->unitOfWorkRegistry->clearAll();
    }

    public function detach(object $entity): void
    {
        $this->unitOfWorkRegistry->detachFromAll($entity);
    }

    public function flushAndClear(): void
    {
        $this->flush();
        $this->clear();
    }

    // Retrieval operations

    /**
     * @param string[] $with Relation property names to force-eager even when lazy: true
     */
    public function find(string $class, mixed $id, array $with = []): ?object
    {
        return $this->readService->find($class, $id, $with);
    }

    /**
     * @param string[] $with Relation property names to force-eager even when lazy: true
     * @return object[]
     */
    public function findAll(string $class, array $with = []): array
    {
        return $this->readService->findAll($class, $with);
    }

    /**
     * Creates an unregistered lazy proxy with a custom loader closure.
     * The proxy is NOT added to the identity map or UnitOfWork — the caller owns its lifecycle.
     * The closure receives the uninitialized ProxyInterface and must load and copy entity data
     * into it, then call $proxy->markProxyInitialized().
     *
     * Use for inverse-side relations (e.g., inverse OneToOne) where the ORM cannot auto-load
     * by a simple ID lookup.
     *
     * @see getReference() for a managed proxy loaded by ID and registered in the identity map
     */
    public function createLazyReference(string $class, \Closure $loader): object
    {
        return $this->readService->createLazyReference($class, $loader);
    }

    /**
     * Returns a managed identity-map proxy for the given class and ID.
     * If the entity is already loaded in any active UnitOfWork, that instance is returned.
     * Otherwise a lazy proxy is created, registered as MANAGED, and returned without hitting the DB.
     * Use this when you need a reference to associate as a FK without loading the full entity.
     *
     * @see createLazyReference() for an unregistered proxy with a custom loader closure
     */
    public function getReference(string $class, mixed $id): object
    {
        return $this->readService->getReference($class, $id);
    }

    /**
     * Reloads an entity's properties from the database, overwriting in-memory state.
     * Throws EntityNotFoundException if the row no longer exists.
     * This is the inverse of find(): find() returns null on miss, refresh() throws.
     *
     * @throws \RuntimeException if the entity has no primary key value or the row is missing
     */
    public function refresh(object $entity): void
    {
        $this->refreshService->refresh($entity);
    }

    // Transaction management
    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollbackTransaction();
    }

    public function transactional(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    // Unit of Work access
    public function getActiveUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWorkRegistry->active();
    }

    /**
     * Creates a new UnitOfWork scoped to this EntityManager and registers it for flush.
     * Use when a bounded sub-operation needs its own identity map and change set,
     * but should commit as part of the next flush() call together with other UoWs.
     *
     * Example — isolated write scope:
     *   $scope = $em->createUnitOfWork();
     *   $em->setActiveUnitOfWork($scope);
     *   $em->persist($entity); // tracked in $scope
     *   $em->flush();          // flushes all registered UoWs, including $scope
     */
    public function createUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWorkRegistry->create();
    }

    public function setActiveUnitOfWork(UnitOfWork $unitOfWork): void
    {
        $this->unitOfWorkRegistry->setActive($unitOfWork);
    }

    public function removeUnitOfWork(UnitOfWork $unitOfWork): void
    {
        $this->unitOfWorkRegistry->remove($unitOfWork);
    }

    public function setUpdateConflictResolutionStrategy(UpdateConflictResolutionStrategy $updateConflictResolutionStrategy): void
    {
        $this->updateConflictResolutionStrategy = $updateConflictResolutionStrategy;
        $this->changeAggregator->setUpdateConflictResolutionStrategy($updateConflictResolutionStrategy);
    }

    public function getUpdateConflictResolutionStrategy(): UpdateConflictResolutionStrategy
    {
        return $this->updateConflictResolutionStrategy;
    }

    public function getFilters(): FilterCollection
    {
        return $this->filters;
    }

    public function createQueryBuilder(?string $entityClass = null): QueryBuilder
    {
        return $this->readService->createQueryBuilder($entityClass);
    }

    // Hydrator access (for advanced customization)
    public function getHydrator(): HydratorInterface
    {
        return $this->hydrator;
    }

    public function setHydrator(HydratorInterface $hydrator): void
    {
        $this->hydrator = $hydrator;
        $this->readService->setHydrator($hydrator);
        $this->refreshService->setHydrator($hydrator);
    }

    /**
     * Load a relationship for an entity.
     */
    public function loadRelation(object $entity, string $relationName): mixed
    {
        $metadata = $this->metadataRegistry->getMetadata($entity::class);
        $relation = $metadata->getRelation($relationName);

        if (!$relation) {
            throw new InvalidArgumentException("Relation '$relationName' not found on entity " . $entity::class);
        }

        return $this->relationshipLoader->load($entity, $relation, [], true);
    }

    /**
     * Create a new collection for relationship management.
     */
    public function createCollection(array $items = []): Collection
    {
        return new Collection($items);
    }

    /**
     * Get the metadata registry.
     */
    public function getMetadataRegistry(): EntityMetadataRegistry
    {
        return $this->metadataRegistry;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Create a lazy-loading proxy for an entity.
     */
    public function createProxy(string $entityClass, mixed $identifier): Proxy\ProxyInterface
    {
        return $this->proxyManager->createProxy($entityClass, $identifier);
    }

    public function setRepositoryFactory(RepositoryFactoryInterface $repositoryFactory): void
    {
        $this->repositoryFactory = $repositoryFactory;
    }

    public function getRepository(string $entityClass): object
    {
        if ($this->repositoryFactory === null) {
            throw new \RuntimeException('No RepositoryFactory configured. Call setRepositoryFactory() first.');
        }

        return $this->repositoryFactory->getRepository($entityClass);
    }
}
