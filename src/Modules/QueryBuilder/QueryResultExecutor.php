<?php

namespace Articulate\Modules\QueryBuilder;

use Articulate\Connection;
use Articulate\Exceptions\TransactionRequiredException;
use Articulate\Schema\CachedEntityResolverInterface;
use Articulate\Schema\EntityMetadata;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use Articulate\Schema\ManagedEntityStoreInterface;

class QueryResultExecutor {
    public function __construct(
        private readonly Connection $connection,
        private readonly QueryResultCache $resultCache,
        private readonly ?HydratorInterface $hydrator = null,
        private readonly ?ManagedEntityStoreInterface $managedEntityStore = null,
        private readonly ?EntityMetadataRegistry $metadataRegistry = null,
    ) {
    }

    public function execute(
        string $sql,
        array $params,
        ?string $entityClass,
        bool $lockForUpdate,
        bool $distinct,
        ?int $limit,
        ?int $offset,
        array $orderBy,
        array $groupBy,
        array $having,
        ?CachedEntityResolverInterface $rowCacheResolver = null,
    ): mixed {
        if ($lockForUpdate && !$this->connection->inTransaction()) {
            throw new TransactionRequiredException('lock() requires an active transaction');
        }

        [$sql, $params] = $this->expandInPlaceholders($sql, $params);

        if (!$lockForUpdate && $this->resultCache->isEnabled()) {
            $cacheKey = $this->resultCache->getCacheId() ?? $this->resultCache->generateCacheKey(
                $entityClass,
                $sql,
                $params,
                $distinct,
                $limit,
                $offset,
                $orderBy,
                $groupBy,
                $having
            );

            $rawResults = $this->resultCache->get($cacheKey);
            if ($rawResults !== null) {
                if (empty($rawResults)) {
                    return [];
                }

                return $this->hydrateResults($rawResults, $entityClass);
            }
        }

        $statement = $this->connection->executeQuery($sql, $params);
        $rawResults = $statement->fetchAll();

        if (!$lockForUpdate && $this->resultCache->isEnabled()) {
            $cacheKey ??= $this->resultCache->getCacheId() ?? $this->resultCache->generateCacheKey(
                $entityClass,
                $sql,
                $params,
                $distinct,
                $limit,
                $offset,
                $orderBy,
                $groupBy,
                $having
            );

            $this->resultCache->set($cacheKey, $rawResults);
        }

        if (empty($rawResults)) {
            return [];
        }

        return $this->hydrateResults($rawResults, $entityClass, $rowCacheResolver);
    }

    private function hydrateResults(array $rawResults, ?string $entityClass, ?CachedEntityResolverInterface $rowCacheResolver = null): mixed
    {
        if ($entityClass && $this->hydrator) {
            $metadata = null;
            if ($this->metadataRegistry !== null) {
                try {
                    $metadata = $this->metadataRegistry->getMetadata($entityClass);
                } catch (\InvalidArgumentException) {
                    $metadata = null;
                }
            }

            $entities = [];

            foreach ($rawResults as $row) {
                if ($metadata !== null && $this->managedEntityStore !== null) {
                    $managedEntity = $this->getManagedEntity($entityClass, $metadata, $row);
                    if ($managedEntity !== null) {
                        $entities[] = $managedEntity;

                        continue;
                    }
                }

                $entity = $this->hydrator->hydrate($entityClass, $row);

                if (is_object($entity) && $this->managedEntityStore !== null) {
                    $this->managedEntityStore->registerManaged($entity, $row);
                }

                if (is_object($entity) && $rowCacheResolver !== null && $metadata !== null) {
                    $this->cacheFreshRow($rowCacheResolver, $entityClass, $metadata, $row);
                }

                $entities[] = $entity;
            }

            return $entities;
        }

        return $rawResults;
    }

    /**
     * A fresh (non-cache, non-identity-map) row just loaded for a cacheable()
     * collection query is also written to the per-entity second-level cache,
     * so a later cache hit's id-list can resolve this id via
     * CachedEntityResolverInterface::tryResolve() without a database read.
     * Single-column primary keys only — composite keys are skipped, same
     * limitation as getManagedEntity() reuses for its own id lookup.
     *
     * @param array<string, mixed> $row
     */
    private function cacheFreshRow(CachedEntityResolverInterface $rowCacheResolver, string $entityClass, EntityMetadata $metadata, array $row): void
    {
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();

        if (count($primaryKeyColumns) !== 1 || !array_key_exists($primaryKeyColumns[0], $row)) {
            return;
        }

        $rowCacheResolver->cacheResolvedRow($entityClass, $row[$primaryKeyColumns[0]], $row);
    }

    private function getManagedEntity(string $entityClass, EntityMetadata $metadata, array $row): ?object
    {
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();

        if (empty($primaryKeyColumns)) {
            return null;
        }

        $id = [];
        foreach ($primaryKeyColumns as $columnName) {
            if (!array_key_exists($columnName, $row)) {
                return null;
            }

            $id[$columnName] = $row[$columnName];
        }

        if (count($id) === 1) {
            $id = array_values($id)[0];
        }

        return $this->managedEntityStore?->tryGetById($entityClass, $id);
    }

    private function expandInPlaceholders(string $sql, array $params): array
    {
        return PlaceholderExpander::expand($sql, $params);
    }
}
