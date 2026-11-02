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
        if (!$entityClass || !$this->hydrator) {
            return $rawResults;
        }

        $metadata = $this->resolveMetadata($entityClass);
        $entities = [];
        $freshRows = [];

        foreach ($rawResults as $row) {
            $managedEntity = $metadata !== null && $this->managedEntityStore !== null
                ? $this->getManagedEntity($entityClass, $metadata, $row)
                : null;
            if ($managedEntity !== null) {
                $entities[] = $managedEntity;

                continue;
            }

            $entity = $this->hydrator->hydrate($entityClass, $row);
            if (is_object($entity)) {
                $this->managedEntityStore?->registerManaged($entity, $row);
                $freshRows[] = $row;
            }

            $entities[] = $entity;
        }

        if ($freshRows !== [] && $rowCacheResolver !== null && $metadata !== null) {
            $this->cacheFreshRows($rowCacheResolver, $entityClass, $metadata, $freshRows);
        }

        return $entities;
    }

    private function resolveMetadata(string $entityClass): ?EntityMetadata
    {
        try {
            return $this->metadataRegistry?->getMetadata($entityClass);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Fresh rows loaded for a cacheable() collection query are also written to
     * the per-entity second-level cache, so a later cache hit's id-list can
     * resolve them via CachedEntityResolverInterface without a database read.
     * Only single-column primary keys are supported, and a row is skipped when
     * it carries columns outside the entity's own mapping (JOINs, extra
     * selects): those may shadow same-named entity columns and must never end
     * up in the per-entity cache.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function cacheFreshRows(CachedEntityResolverInterface $rowCacheResolver, string $entityClass, EntityMetadata $metadata, array $rows): void
    {
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();
        if (count($primaryKeyColumns) !== 1) {
            return;
        }

        $ownColumns = $this->ownColumnNames($metadata);
        $pairs = [];
        foreach ($rows as $row) {
            if (!array_key_exists($primaryKeyColumns[0], $row) || array_diff(array_keys($row), $ownColumns) !== []) {
                continue;
            }

            $pairs[] = [$row[$primaryKeyColumns[0]], $row];
        }

        if ($pairs !== []) {
            $rowCacheResolver->cacheResolvedRows($entityClass, $pairs);
        }
    }

    /**
     * @return list<string>
     */
    private function ownColumnNames(EntityMetadata $metadata): array
    {
        $columns = $metadata->getColumnNames();
        foreach ($metadata->getColumnRelations() as $relation) {
            $columns[] = $relation->getColumnName();
        }

        return $columns;
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
