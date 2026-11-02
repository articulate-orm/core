<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\Generators\GeneratorRegistry;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

class EntityManagerOptions {
    public ?ChangeTrackingStrategy $changeTrackingStrategy = null;

    public ?HydratorInterface $hydrator = null;

    public ?GeneratorRegistry $generatorRegistry = null;

    public ?EntityMetadataRegistry $metadataRegistry = null;

    public ?QueryExecutor $queryExecutor = null;

    public ?UpdateConflictResolutionStrategy $updateConflictResolutionStrategy = null;

    public ?CacheItemPoolInterface $resultCache = null;

    public ?RepositoryFactoryInterface $repositoryFactory = null;

    public ?CacheItemPoolInterface $statementCache = null;

    public ?CacheItemPoolInterface $secondLevelCache = null;

    public int $secondLevelCacheTtl = CollectionCacheInterface::DEFAULT_TTL;

    public ?CacheItemPoolInterface $metadataCache = null;

    public ?LoggerInterface $logger = null;
}
