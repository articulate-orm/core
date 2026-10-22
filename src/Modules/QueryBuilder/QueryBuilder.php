<?php

namespace Articulate\Modules\QueryBuilder;

use Articulate\Connection;
use Articulate\Exceptions\CursorPaginationException;
use Articulate\Exceptions\TransactionRequiredException;
use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\Cache\NullCollectionCache;
use Articulate\Modules\QueryBuilder\Filter\FilterCollection;
use Articulate\Schema\CachedEntityResolverInterface;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use Articulate\Schema\ManagedEntityStoreInterface;
use Articulate\Utils\ReflectionCache;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;

class QueryBuilder {
    private Connection $connection;

    private ?HydratorInterface $hydrator;

    private bool $hydratorExplicitlySet = false;

    private ?string $entityClass = null;

    private ?EntityMetadataRegistry $metadataRegistry;

    private string $from = '';

    private array $select = [];

    private WhereClauseBuilder $whereBuilder;

    private array $having = []; // [['operator' => 'AND|OR', 'condition' => '...', 'params' => [...]], ...]

    private array $joins = []; // [['sql' => '...', 'params' => [...]], ...]

    private bool $distinct = false;

    private ?string $rawSql = null;

    private array $rawParams = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private array $orderBy = [];

    private ?Cursor $cursor = null;

    private ?int $cursorLimit = null;

    private CursorCodec $cursorCodec;

    private CursorPaginationHandler $cursorPaginationHandler;

    private array $groupBy = [];

    private ?ManagedEntityStoreInterface $managedEntityStore = null;

    private bool $lockForUpdate = false;

    private QueryResultCache $resultCache;

    private QueryResultExecutor $resultExecutor;

    private SqlCompiler $sqlCompiler;

    private FilterCollection $filters;

    /** @var string[] */
    private array $disabledFilters = [];

    private DmlOperationHandler $dmlHandler;

    private SqlStatementCache $statementCache;

    private QueryResultSecondLevelCache $secondLevelCache;

    private ?CachedEntityResolverInterface $cachedEntityResolver;

    private int $secondLevelCacheDefaultTtl;

    public function __construct(
        Connection $connection,
        ?HydratorInterface $hydrator = null,
        ?EntityMetadataRegistry $metadataRegistry = null,
        ?CacheItemPoolInterface $resultCache = null,
        ?FilterCollection $filters = null,
        ?CacheItemPoolInterface $statementCache = null,
        CollectionCacheInterface $collectionCache = new NullCollectionCache(),
        ?CachedEntityResolverInterface $cachedEntityResolver = null,
        int $secondLevelCacheDefaultTtl = CollectionCacheInterface::DEFAULT_TTL,
    ) {
        $this->connection = $connection;
        $this->hydrator = $hydrator;
        $this->entityClass = null;
        $this->metadataRegistry = $metadataRegistry;
        $this->resultCache = new QueryResultCache($resultCache);
        $this->statementCache = new SqlStatementCache($statementCache);
        $this->sqlCompiler = new SqlCompiler($metadataRegistry);
        $this->cursorCodec = new CursorCodec();
        $this->filters = $filters ?? new FilterCollection();
        $this->cursorPaginationHandler = new CursorPaginationHandler($this->cursorCodec, $metadataRegistry);
        $this->resultExecutor = new QueryResultExecutor($connection, $this->resultCache, $hydrator, null, $metadataRegistry);
        $this->dmlHandler = new DmlOperationHandler($metadataRegistry);
        $this->whereBuilder = new WhereClauseBuilder(
            fn () => $this->createSubQueryBuilder(),
            $this->sqlCompiler
        );
        $this->secondLevelCache = new QueryResultSecondLevelCache($collectionCache, $connection->getCacheNamespace());
        $this->cachedEntityResolver = $cachedEntityResolver;
        $this->secondLevelCacheDefaultTtl = $secondLevelCacheDefaultTtl;
    }

    public function createSubQueryBuilder(): QueryBuilder
    {
        $subBuilder = new self($this->connection, $this->hydrator, $this->metadataRegistry, null, $this->filters);
        $subBuilder->sqlCompiler->setEntityClass($this->entityClass);
        $subBuilder->disabledFilters = $this->disabledFilters;

        return $subBuilder;
    }

    public function withoutFilter(string $name): self
    {
        if (!in_array($name, $this->disabledFilters, true)) {
            $this->disabledFilters[] = $name;
        }

        return $this;
    }

    public function select(string ...$fields): self
    {
        $this->select = array_merge($this->select, $fields);

        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->assertValidTableIdentifier($table);
        if ($alias !== null) {
            $this->assertValidFieldIdentifier($alias);
        }
        $this->from = $alias ? "{$table} {$alias}" : $table;

        return $this;
    }

    public function where(string|callable $columnOrCallback, mixed $operatorOrValue = null, mixed $value = null): self
    {
        $this->whereBuilder->where($columnOrCallback, $operatorOrValue, $value);

        return $this;
    }

    public function orWhere(string|callable $columnOrCallback, mixed $operatorOrValue = null, mixed $value = null): self
    {
        $this->whereBuilder->orWhere($columnOrCallback, $operatorOrValue, $value);

        return $this;
    }

    public function whereIn(string $column, array|QueryBuilder $values): self
    {
        $this->whereBuilder->whereIn($column, $values);

        return $this;
    }

    public function whereNotIn(string $column, array|QueryBuilder $values): self
    {
        $this->whereBuilder->whereNotIn($column, $values);

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->whereBuilder->whereNull($column);

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->whereBuilder->whereNotNull($column);

        return $this;
    }

    public function whereExists(QueryBuilder $subquery): self
    {
        $this->whereBuilder->whereExists($subquery);

        return $this;
    }

    public function apply(CriteriaInterface $criteria): self
    {
        $criteria->apply($this);

        return $this;
    }

    public function whereNot(callable $callback): self
    {
        $this->whereBuilder->whereNot($callback);

        return $this;
    }

    public function orWhereExists(QueryBuilder $subquery): self
    {
        $this->whereBuilder->orWhereExists($subquery);

        return $this;
    }

    public function getWhereConditions(): array
    {
        return $this->whereBuilder->getConditions();
    }

    public function insert(object|array|string $entitiesOrTable): self
    {
        $this->dmlHandler->insert($entitiesOrTable, $this->createDmlContext());

        return $this;
    }

    public function values(array $values): self
    {
        $this->dmlHandler->values($values);

        return $this;
    }

    public function update(object|string $entityOrTable): self
    {
        $this->dmlHandler->update($entityOrTable, $this->createDmlContext());

        return $this;
    }

    public function set(string|array $column, mixed $value = null): self
    {
        $this->dmlHandler->set($column, $value);

        return $this;
    }

    public function delete(object|string $entityOrTable): self
    {
        $this->dmlHandler->delete($entityOrTable, $this->createDmlContext());

        return $this;
    }

    public function returning(string ...$columns): self
    {
        $this->dmlHandler->returning(...$columns);

        return $this;
    }

    private function createDmlContext(): DmlContext
    {
        return new class($this) implements DmlContext {
            public function __construct(private readonly QueryBuilder $qb)
            {
            }

            public function getEntityClass(): ?string
            {
                return $this->qb->getEntityClass();
            }

            public function getFrom(): string
            {
                return $this->qb->getFrom();
            }

            public function setEntityClass(string $entityClass): void
            {
                $this->qb->setEntityClass($entityClass);
            }

            public function setFrom(string $from): void
            {
                $this->qb->setFromTable($from);
            }

            public function addWhere(string $clause, mixed ...$values): void
            {
                $this->qb->addWhereCondition($clause, ...$values);
            }
        };
    }

    // Reset methods
    public function resetWhere(): self
    {
        $this->whereBuilder->reset();

        return $this;
    }

    public function resetSelect(): self
    {
        $this->select = [];

        return $this;
    }

    public function resetJoins(): self
    {
        $this->joins = [];

        return $this;
    }

    public function resetOrderBy(): self
    {
        $this->orderBy = [];

        return $this;
    }

    public function resetGroupBy(): self
    {
        $this->groupBy = [];

        return $this;
    }

    public function resetHaving(): self
    {
        $this->having = [];

        return $this;
    }

    public function reset(): self
    {
        $this->select = [];
        $this->from = '';
        $this->whereBuilder->reset();
        $this->having = [];
        $this->joins = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->cursor = null;
        $this->cursorLimit = null;
        $this->distinct = false;
        $this->lockForUpdate = false;
        $this->rawSql = null;
        $this->rawParams = [];
        $this->entityClass = null;
        $this->disabledFilters = [];
        $this->resultCache->disable();
        $this->secondLevelCache->disable();
        $this->dmlHandler->reset();

        return $this;
    }

    // Aggregate functions
    public function count(string $column = '*', ?string $alias = null): self
    {
        $aggregate = "COUNT({$column})";
        if ($alias) {
            $aggregate .= " as {$alias}";
        }
        $this->select[] = $aggregate;

        return $this;
    }

    public function sum(string $column, ?string $alias = null): self
    {
        $aggregate = "SUM({$column})";
        if ($alias) {
            $aggregate .= " as {$alias}";
        }
        $this->select[] = $aggregate;

        return $this;
    }

    public function avg(string $column, ?string $alias = null): self
    {
        $aggregate = "AVG({$column})";
        if ($alias) {
            $aggregate .= " as {$alias}";
        }
        $this->select[] = $aggregate;

        return $this;
    }

    public function max(string $column, ?string $alias = null): self
    {
        $aggregate = "MAX({$column})";
        if ($alias) {
            $aggregate .= " as {$alias}";
        }
        $this->select[] = $aggregate;

        return $this;
    }

    public function min(string $column, ?string $alias = null): self
    {
        $aggregate = "MIN({$column})";
        if ($alias) {
            $aggregate .= " as {$alias}";
        }
        $this->select[] = $aggregate;

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    // HAVING clause
    public function having(string $condition, mixed ...$params): self
    {
        $this->having[] = [
            'operator' => 'AND',
            'condition' => $condition,
            'params' => $params,
        ];

        return $this;
    }

    public function orHaving(string $condition, mixed ...$params): self
    {
        $this->having[] = [
            'operator' => 'OR',
            'condition' => $condition,
            'params' => $params,
        ];

        return $this;
    }

    // Raw SQL methods
    public function raw(string $sql, mixed ...$params): self
    {
        $this->rawSql = $sql;
        // Handle both single array parameter and multiple parameters
        if (count($params) === 1 && is_array($params[0])) {
            $this->rawParams = $params[0];
        } else {
            $this->rawParams = $params;
        }

        return $this;
    }

    public function selectRaw(string $expression, mixed ...$params): self
    {
        $this->select[] = ['raw' => true, 'expression' => $expression, 'params' => $params];

        return $this;
    }

    public function selectSub(QueryBuilder $subquery, ?string $alias = null): self
    {
        $subquerySql = $subquery->getSQL();
        $subqueryParams = $subquery->getParameters();

        $expression = "({$subquerySql})";
        if ($alias) {
            $expression .= " as {$alias}";
        }

        $this->select[] = ['raw' => true, 'expression' => $expression, 'params' => $subqueryParams];

        return $this;
    }

    public function whereRaw(string $condition, mixed ...$params): self
    {
        $this->whereBuilder->whereRaw($condition, ...$params);

        return $this;
    }

    public function join(string $table, string $condition, mixed ...$params): self
    {
        $this->assertValidJoinTable($table);
        $this->joins[] = [
            'sql' => "JOIN {$table} ON {$condition}",
            'params' => $params,
        ];

        return $this;
    }

    public function leftJoin(string $table, string $condition, mixed ...$params): self
    {
        $this->assertValidJoinTable($table);
        $this->joins[] = [
            'sql' => "LEFT JOIN {$table} ON {$condition}",
            'params' => $params,
        ];

        return $this;
    }

    public function rightJoin(string $table, string $condition, mixed ...$params): self
    {
        $this->assertValidJoinTable($table);
        $this->joins[] = [
            'sql' => "RIGHT JOIN {$table} ON {$condition}",
            'params' => $params,
        ];

        return $this;
    }

    public function crossJoin(string $table): self
    {
        $this->assertValidJoinTable($table);
        $this->joins[] = [
            'sql' => "CROSS JOIN {$table}",
            'params' => [],
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $normalized = strtoupper(trim($direction));
        if ($normalized !== 'ASC' && $normalized !== 'DESC') {
            throw new InvalidArgumentException("Invalid ORDER BY direction '{$direction}'. Must be ASC or DESC.");
        }

        $this->assertValidFieldIdentifier($field);

        $this->orderBy[] = "{$field} {$normalized}";

        return $this;
    }

    public function cursor(string $token): self
    {
        if (empty($this->orderBy)) {
            throw new CursorPaginationException('ORDER BY clause is required for cursor pagination');
        }

        if (count($this->orderBy) > 2) {
            throw new CursorPaginationException('Cursor pagination supports maximum 2 ORDER BY columns');
        }

        $this->cursor = $this->cursorCodec->decode($token);

        return $this;
    }

    public function cursorLimit(int $limit): self
    {
        $this->cursorLimit = $limit;

        return $this;
    }

    public function groupBy(string ...$fields): self
    {
        foreach ($fields as $field) {
            $this->assertValidFieldIdentifier($field);
        }
        $this->groupBy = array_merge($this->groupBy, $fields);

        return $this;
    }

    public function lock(): self
    {
        $this->lockForUpdate = true;

        return $this;
    }

    /**
     * Enable result caching for this query.
     *
     * Note: Locked queries (using lock()) will never be cached, even if caching is enabled.
     * This ensures transactional integrity.
     *
     * @param int $lifetime Cache lifetime in seconds (must be positive)
     * @param string|null $resultCacheId Optional custom cache key. If not provided, key is auto-generated from query characteristics.
     * @return self
     * @throws InvalidArgumentException If cache pool is not configured or lifetime is invalid
     */
    public function enableResultCache(int $lifetime, ?string $resultCacheId = null): self
    {
        $this->resultCache->enable($lifetime, $resultCacheId);

        return $this;
    }

    /**
     * Disable result caching for this query.
     *
     * @return self
     */
    public function disableResultCache(): self
    {
        $this->resultCache->disable();

        return $this;
    }

    public function setResultCacheGeneration(int $generation): void
    {
        $this->resultCache->setGeneration($generation);
    }

    /**
     * Opt this query into the second-level collection (query-region) cache:
     * the first getResult() call caches the matched id-list, a repeated
     * identical call resolves entirely from cache. Silent no-op when the
     * query won't hydrate full entities via the default ObjectHydrator
     * (aggregates, specific column selection, explicit custom hydrator) —
     * same condition getResult() already uses for hydration bypass. See
     * ADR-0002.
     *
     * Omitted $ttl falls back to the owning EntityManager's configured
     * secondLevelCacheTtl.
     */
    public function cacheable(?int $ttl = null): self
    {
        $this->secondLevelCache->enable($ttl);

        return $this;
    }

    public function setHydrator(?HydratorInterface $hydrator): self
    {
        $this->hydrator = $hydrator;
        $this->hydratorExplicitlySet = true;
        $this->resultExecutor = new QueryResultExecutor(
            $this->connection,
            $this->resultCache,
            $hydrator,
            $this->managedEntityStore,
            $this->metadataRegistry
        );

        return $this;
    }

    public function getHydrator(): ?HydratorInterface
    {
        return $this->hydrator;
    }

    /**
     * Set the store that will reuse and register managed entities for this query.
     */
    public function setManagedEntityStore(?ManagedEntityStoreInterface $managedEntityStore): self
    {
        $this->managedEntityStore = $managedEntityStore;
        $this->resultExecutor = new QueryResultExecutor(
            $this->connection,
            $this->resultCache,
            $this->hydrator,
            $managedEntityStore,
            $this->metadataRegistry
        );

        return $this;
    }

    public function setEntityClass(string $entityClass): self
    {
        $this->entityClass = $entityClass;
        $this->sqlCompiler->setEntityClass($entityClass);

        // If no FROM clause set yet, try to set it from entity
        if (empty($this->from)) {
            $this->from($this->resolveTableName($entityClass));
        }

        return $this;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function setFromTable(string $table): void
    {
        $this->from = $table;
    }

    public function addWhereCondition(string $condition, mixed ...$params): void
    {
        $actualParams = count($params) === 1 && is_array($params[0]) ? $params[0] : $params;

        $this->whereBuilder->addCondition($condition, $actualParams, 'AND');
    }

    private function getFilteredWhere(): array
    {
        $where = $this->whereBuilder->getConditions();

        if ($this->entityClass !== null && $this->rawSql === null && $this->metadataRegistry !== null) {
            try {
                $metadata = $this->metadataRegistry->getMetadata($this->entityClass);
                foreach ($this->filters->getActiveConditions($metadata) as $name => $condition) {
                    if (!in_array($name, $this->disabledFilters, true)) {
                        $where[] = ['operator' => 'AND', 'condition' => $condition, 'params' => [], 'group' => null];
                    }
                }
            } catch (InvalidArgumentException) {
                // Entity class may not have registered metadata; filters are skipped
            }
        }

        return $where;
    }

    /** @return array{0: string, 1: array} */
    private function build(): array
    {
        if ($this->rawSql !== null) {
            return [$this->rawSql, $this->rawParams];
        }

        $dmlCommand = $this->dmlHandler->getCommand();

        if ($dmlCommand === 'insert') {
            if (empty($this->from)) {
                throw new InvalidArgumentException('INSERT requires a table name');
            }

            return $this->sqlCompiler->compileInsert(
                $this->from,
                $this->dmlHandler->getInsertColumns(),
                $this->dmlHandler->getInsertValues(),
                $this->dmlHandler->getReturning()
            );
        }

        $where = $this->getFilteredWhere();

        if ($dmlCommand === 'update') {
            if (empty($this->from)) {
                throw new InvalidArgumentException('UPDATE requires a table name');
            }

            return $this->sqlCompiler->compileUpdate(
                $this->from,
                $this->dmlHandler->getUpdateSet(),
                $where,
                $this->dmlHandler->getReturning()
            );
        }

        if ($dmlCommand === 'delete') {
            if (empty($this->from)) {
                throw new InvalidArgumentException('DELETE requires a table name');
            }

            return $this->sqlCompiler->compileDelete(
                $this->from,
                $where,
                $this->dmlHandler->getReturning()
            );
        }

        $limitToUse = $this->cursorLimit !== null ? $this->cursorLimit + 1 : $this->limit;
        $offsetToUse = $this->cursor !== null ? null : $this->offset;

        if ($this->statementCache->isEnabled()) {
            $cacheKey = $this->buildStructuralCacheKey($where);
            $cachedSql = $this->statementCache->get($cacheKey);

            if ($cachedSql !== null) {
                $whereWithCursor = $this->sqlCompiler->buildWhereWithCursor($where, $this->orderBy, $this->cursor);
                $params = $this->sqlCompiler->collectParametersPublic(
                    $this->select,
                    $this->joins,
                    $whereWithCursor,
                    $this->having
                );

                return [$cachedSql, $params];
            }
        }

        [$sql, $params] = $this->sqlCompiler->compile(
            $this->rawSql,
            $this->rawParams,
            $this->from,
            $this->select,
            $this->joins,
            $where,
            $this->groupBy,
            $this->having,
            $this->orderBy,
            $limitToUse,
            $offsetToUse,
            $this->distinct,
            $this->lockForUpdate,
            $this->cursor
        );

        if (isset($cacheKey)) {
            $this->statementCache->set($cacheKey, $sql);
        }

        return [$sql, $params];
    }

    private function buildStructuralCacheKey(array $where): string
    {
        $structural = [
            'entityClass' => $this->entityClass,
            'from' => $this->from,
            'select' => $this->extractSelectExpressions($this->select),
            'joins' => array_column($this->joins, 'sql'),
            'where' => $this->extractConditionStructure($where),
            'groupBy' => $this->groupBy,
            'having' => array_map(
                fn (array $h) => ['operator' => $h['operator'], 'condition' => $h['condition']],
                $this->having
            ),
            'orderBy' => $this->orderBy,
            'limit' => $this->cursorLimit !== null ? $this->cursorLimit + 1 : $this->limit,
            'offset' => $this->cursor !== null ? null : $this->offset,
            'distinct' => $this->distinct,
            'lockForUpdate' => $this->lockForUpdate,
            'cursor' => $this->cursor ? $this->cursor->getDirection()->value : null,
            'disabledFilters' => $this->disabledFilters,
        ];

        return 'sqls_' . hash('sha256', json_encode($structural));
    }

    private function extractSelectExpressions(array $select): array
    {
        return array_map(
            fn (mixed $item) => is_array($item) ? $item['expression'] : $item,
            $select
        );
    }

    private function extractConditionStructure(array $conditions): array
    {
        return array_map(function (array $cond) {
            return [
                'operator' => $cond['operator'],
                'condition' => $cond['group'] !== null ? null : $cond['condition'],
                'group' => $cond['group'] !== null ? $this->extractConditionStructure($cond['group']) : null,
            ];
        }, $conditions);
    }

    public function getSQL(): string
    {
        return $this->build()[0];
    }

    public function getParameters(): array
    {
        return $this->build()[1];
    }

    public function getResult(?string $entityClass = null): mixed
    {
        [$targetClass, $usesDefaultHydration] = $this->resolveHydrationTarget($entityClass);

        if ($targetClass !== null && $this->hydrator === null) {
            throw new \LogicException('QueryBuilder requires a HydratorInterface to hydrate entity results.');
        }

        $sql = $this->getSQL();
        $params = $this->getParameters();

        // cacheable() only ever applies when this call will hydrate full entities
        // via the default ObjectHydrator — same condition as the bypass above. A
        // non-null $cacheKey below is this method's single "is this call cache
        // eligible" flag for the rest of the method.
        $cacheKey = $this->isCacheEligible($usesDefaultHydration, $targetClass)
            ? $this->secondLevelCache->buildCacheKey($targetClass, $sql, $params)
            : null;

        if ($cacheKey !== null) {
            $cachedResult = $this->tryServeFromCache($targetClass, $cacheKey);
            if ($cachedResult !== null) {
                return $cachedResult;
            }
            // Miss, or resolution failed (e.g. an id no longer resolvable without a
            // DB read) — fall through to a fresh query rather than serve a partial list.
        }

        $results = $this->resultExecutor->execute(
            $sql,
            $params,
            $targetClass,
            $this->lockForUpdate,
            $this->distinct,
            $this->limit,
            $this->offset,
            $this->orderBy,
            $this->groupBy,
            $this->having,
            $cacheKey !== null ? $this->cachedEntityResolver : null,
        );

        if ($cacheKey !== null) {
            $this->maybeStoreIdsInCache($cacheKey, $results, $targetClass);
        }

        return $results;
    }

    /**
     * Resolves the entity class getResult() should hydrate into, applying the
     * same aggregate/specific-column-selection hydration bypass it has always
     * used (ObjectHydrator requires full rows; custom hydrators always run).
     *
     * @return array{0: ?string, 1: bool} [$targetClass, $usesDefaultHydration]
     */
    private function resolveHydrationTarget(?string $entityClass): array
    {
        $targetClass = $entityClass ?? $this->entityClass;
        $usesDefaultHydration = !$this->hydratorExplicitlySet;

        if ($usesDefaultHydration && ($this->hasAggregateFunction() || $this->hasSpecificColumnSelection())) {
            $targetClass = null;
        }

        return [$targetClass, $usesDefaultHydration];
    }

    private function isCacheEligible(bool $usesDefaultHydration, ?string $targetClass): bool
    {
        return $this->secondLevelCache->isEnabled()
            && !$this->lockForUpdate
            && $usesDefaultHydration
            && $targetClass !== null
            && $this->cachedEntityResolver !== null
            && $this->hasSingleColumnPrimaryKey($targetClass);
    }

    private function hasSingleColumnPrimaryKey(string $entityClass): bool
    {
        if ($this->metadataRegistry === null) {
            return false;
        }

        try {
            return count($this->metadataRegistry->getMetadata($entityClass)->getPrimaryKeyColumns()) === 1;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return list<object>|null null on cache miss or failed resolution
     */
    private function tryServeFromCache(string $targetClass, string $cacheKey): ?array
    {
        $cachedIds = $this->secondLevelCache->getCachedIds($cacheKey);
        if ($cachedIds === null) {
            return null;
        }

        return $this->resolveCachedIds($targetClass, $cachedIds);
    }

    private function maybeStoreIdsInCache(string $cacheKey, mixed $results, string $targetClass): void
    {
        if (!is_array($results)) {
            return;
        }

        $ids = $this->extractIds($results, $targetClass);
        if ($ids !== null) {
            $this->secondLevelCache->storeIds($cacheKey, $ids, $this->secondLevelCacheDefaultTtl);
        }
    }

    /**
     * @param list<mixed> $ids
     * @return list<object>|null null if any id cannot be resolved without a database read
     */
    private function resolveCachedIds(string $entityClass, array $ids): ?array
    {
        return $this->cachedEntityResolver->tryResolveMany($entityClass, $ids);
    }

    /**
     * @param list<object> $entities
     * @return list<mixed>|null
     */
    private function extractIds(array $entities, string $entityClass): ?array
    {
        if ($this->metadataRegistry === null) {
            return null;
        }

        $metadata = $this->metadataRegistry->getMetadata($entityClass);
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();

        if (count($primaryKeyColumns) !== 1) {
            return null;
        }

        $propertyName = $metadata->getPropertyNameForColumn($primaryKeyColumns[0]);
        if ($propertyName === null) {
            return null;
        }

        $ids = [];
        foreach ($entities as $entity) {
            $property = ReflectionCache::getProperty($entity::class, $propertyName);
            $ids[] = $property->getValue($entity);
        }

        return $ids;
    }

    /**
     * Iterate over all matching rows in chunks of $size using LIMIT/OFFSET pagination.
     * Each iteration yields an array of up to $size results.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    public function chunk(int $size): \Generator
    {
        if ($size <= 0) {
            throw new InvalidArgumentException('Chunk size must be a positive integer.');
        }

        $savedLimit = $this->limit;
        $savedOffset = $this->offset;

        try {
            $this->limit = $size;
            $offset = 0;

            while (true) {
                $this->offset = $offset;
                $results = $this->getResult();
                $batch = is_array($results) ? $results : [];

                if (empty($batch)) {
                    break;
                }

                yield $batch;

                if (count($batch) < $size) {
                    break;
                }

                $offset += $size;
            }
        } finally {
            $this->limit = $savedLimit;
            $this->offset = $savedOffset;
        }
    }

    public function getSingleResult(?string $entityClass = null): mixed
    {
        $originalLimit = $this->limit;
        $this->limit = 1;

        try {
            $results = $this->getResult($entityClass);

            return is_array($results) ? ($results[0] ?? null) : $results;
        } finally {
            $this->limit = $originalLimit;
        }
    }

    public function getCursorPaginatedResult(?string $entityClass = null): CursorPaginator
    {
        $this->cursorPaginationHandler->validateCursorPagination($this->orderBy, $this->cursorLimit);

        $results = $this->getResult($entityClass);
        $items = is_array($results) ? $results : [];

        return $this->cursorPaginationHandler->createPaginator(
            $items,
            $this->orderBy,
            $this->cursor,
            $this->cursorLimit,
            $entityClass ?? $this->entityClass
        );
    }

    public function execute(): mixed
    {
        $dmlCommand = $this->dmlHandler->getCommand();

        if ($dmlCommand !== null) {
            return $this->dmlHandler->execute(
                $this->connection,
                $this->sqlCompiler,
                $this->getFilteredWhere(),
                $this->from,
                $this->lockForUpdate,
                fn (string $sql, array $params) => $this->expandInPlaceholders($sql, $params)
            );
        }

        if ($this->lockForUpdate && !$this->connection->inTransaction()) {
            throw new TransactionRequiredException('lock() requires an active transaction');
        }

        $sql = $this->getSQL();
        $params = $this->getParameters();
        [$sql, $params] = $this->expandInPlaceholders($sql, $params);

        return $this->connection->executeQuery($sql, $params)->rowCount();
    }

    private function resolveTableName(string $entityClass): string
    {
        if ($this->metadataRegistry) {
            return $this->metadataRegistry->getTableName($entityClass);
        }

        $className = basename(str_replace('\\', '/', $entityClass));

        return strtolower($className) . 's';
    }

    private function expandInPlaceholders(string $sql, array $params): array
    {
        return PlaceholderExpander::expand($sql, $params);
    }

    private function hasAggregateFunction(): bool
    {
        foreach ($this->select as $selectItem) {
            if (!is_string($selectItem)) {
                continue;
            }
            if (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i', $selectItem)) {
                return true;
            }
        }

        return false;
    }

    private function hasSpecificColumnSelection(): bool
    {
        // If select is empty, SQL compiler will use SELECT *
        if (empty($this->select)) {
            return false;
        }

        // If select contains only '*', it's not specific
        if (count($this->select) === 1 && $this->select[0] === '*') {
            return false;
        }

        // Otherwise, we have specific column selection
        return true;
    }

    private function assertValidTableIdentifier(string $table): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $table)) {
            throw new InvalidArgumentException("Invalid table identifier '{$table}'.");
        }
    }

    // join() accepts "table alias" as one string — validate each part separately
    private function assertValidJoinTable(string $tableWithOptionalAlias): void
    {
        $parts = preg_split('/\s+/', trim($tableWithOptionalAlias), 2);
        $this->assertValidTableIdentifier($parts[0]);
        if (isset($parts[1])) {
            $this->assertValidFieldIdentifier($parts[1]);
        }
    }

    private function assertValidFieldIdentifier(string $field): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $field)) {
            throw new InvalidArgumentException("Invalid field identifier '{$field}'.");
        }
    }
}
