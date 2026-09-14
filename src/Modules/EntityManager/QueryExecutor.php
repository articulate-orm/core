<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Attributes\Reflection\ReflectionManyToMany;
use Articulate\Attributes\Reflection\ReflectionMorphToMany;
use Articulate\Attributes\Reflection\ReflectionProperty;
use Articulate\Attributes\Relations\MorphTypeRegistry;
use Articulate\Collection\MappingCollection;
use Articulate\Connection;
use Articulate\Exceptions\ManagedVersionColumnException;
use Articulate\Exceptions\OptimisticLockException;
use Articulate\Modules\Generators\GeneratorRegistry;
use Articulate\Schema\EntityMetadata;
use Articulate\Utils\ReflectionCache;
use ReflectionProperty as NativeReflectionProperty;

/**
 * Handles low-level SQL query execution for entity operations.
 *
 * This class is responsible for executing INSERT, UPDATE, DELETE, and SELECT
 * operations against the database, but has no knowledge of entity management
 * or change tracking.
 */
class QueryExecutor {
    /**
     * @var ReflectionEntity[]
     * Static cache keyed by entity class name. Bounded by the number of distinct entity classes
     * in the process — safe for long-running processes (FPM, Swoole, CLI daemons).
     */
    private static array $reflectionEntityCache = [];

    /** @var EntityMetadata[] */
    private array $entityMetadataCache = [];

    public function __construct(
        private Connection $connection,
        private GeneratorRegistry $generatorRegistry
    ) {
    }

    private function getReflectionEntity(string $entityClass): ReflectionEntity
    {
        return self::$reflectionEntityCache[$entityClass] ??= new ReflectionEntity($entityClass);
    }

    /**
     * Executes an INSERT operation for a single entity.
     *
     * @param object $entity The entity to insert
     * @return mixed The generated ID if applicable, null otherwise
     */
    public function executeInsert(object $entity): mixed
    {
        $reflectionEntity = $this->getReflectionEntity($entity::class);
        $tableName = $reflectionEntity->getTableName();

        // Get all entity properties (excluding relations)
        $properties = array_filter(
            iterator_to_array($reflectionEntity->getEntityProperties()),
            fn ($property) => $property instanceof ReflectionProperty
        );

        // Prepare column names and values
        $columns = [];
        $placeholders = [];
        $values = [];
        $pkColumnName = null;

        foreach ($properties as $property) {
            $columnName = $property->getColumnName();
            $fieldName = $property->getFieldName();

            // Get the value from the entity
            $reflectionProperty = new NativeReflectionProperty($entity, $fieldName);
            $reflectionProperty->setAccessible(true);
            $value = $reflectionProperty->isInitialized($entity) ? $reflectionProperty->getValue($entity) : null;

            // Skip primary key columns with null values (they should be auto-generated)
            if ($property->isPrimaryKey() && $value === null) {
                $pkColumnName = $columnName;

                continue;
            }

            // Skip null values for non-nullable properties that don't have defaults
            // (let the database handle defaults)
            if ($value === null && !$property->isNullable() && $property->getDefaultValue() === null) {
                continue;
            }

            $columns[] = $columnName;
            $placeholders[] = '?';
            $values[] = $this->normalizeForDatabase($value);
        }

        // Handle MorphTo relationships
        $this->addMorphToColumns($entity, $columns, $placeholders, $values);

        $this->addManyToOneColumns($entity, $columns, $placeholders, $values);

        $id = $this->extractEntityId($entity);
        $preGeneratedId = null;

        if ($id === null || $id === '') {
            $generatedId = $this->generateNextId($entity::class);
            if ($generatedId !== null) {
                $preGeneratedId = $generatedId;
                foreach ($reflectionEntity->getEntityFieldsProperties() as $property) {
                    if ($property instanceof ReflectionProperty && $property->isPrimaryKey()) {
                        $columns[] = $property->getColumnName();
                        $placeholders[] = '?';
                        $values[] = $generatedId;

                        break;
                    }
                }
                $this->setEntityId($entity, $generatedId);
            }
        }

        $dbAssignedId = $preGeneratedId === null && ($id === null || $id === '');

        if ($dbAssignedId && $pkColumnName !== null && $this->connection->getDriverName() === 'pgsql') {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
                $tableName,
                implode(', ', $columns),
                implode(', ', $placeholders),
                $pkColumnName
            );
            $stmt = $this->connection->executeQuery($sql, $values);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $lastId = (int) $row[$pkColumnName];
            $this->setEntityId($entity, $lastId);

            return $lastId;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tableName,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->connection->executeQuery($sql, $values);

        if ($preGeneratedId !== null) {
            return $preGeneratedId;
        }

        if ($id !== null && $id !== '') {
            return $id;
        }

        $lastId = (int) $this->connection->lastInsertId();
        $this->setEntityId($entity, $lastId);

        return $lastId;
    }

    /**
     * Executes an UPDATE operation for a single entity.
     *
     * @param object $entity The entity to update
     * @param array $changes The changed properties (fieldName => newValue)
     * @return DeferredVersionBump|null In-memory #[Version] reconciliation for the caller to apply/revert; see EntityManager::flush().
     */
    public function executeUpdate(object $entity, array $changes): ?DeferredVersionBump
    {
        if (empty($changes)) {
            return null;
        }

        $reflectionEntity = $this->getReflectionEntity($entity::class);
        $tableName = $reflectionEntity->getTableName();
        $metadata = $this->entityMetadataCache[$entity::class] ??= new EntityMetadata($entity::class);

        // Prepare SET clause from changes
        $setParts = [];
        $values = [];
        $versionColumns = $metadata->getVersionColumns();

        foreach ($changes as $columnName => $newValue) {
            // The #[Version] column is ORM-managed: it is bumped server-side ("col = col + 1",
            // appended below) and checked in the WHERE clause against the tracked value. The ORM
            // never marks it dirty itself, so its presence here is a manual assignment — which
            // would desync the lock check (and duplicate the SET target, a hard error on
            // PostgreSQL). Reject it rather than silently dropping it.
            if (in_array($columnName, $versionColumns, true)) {
                throw ManagedVersionColumnException::forColumn($entity::class, $columnName);
            }

            // Find the property metadata by column name (changes are keyed by column name)
            $property = null;
            foreach (iterator_to_array($reflectionEntity->getEntityProperties()) as $prop) {
                if ($prop instanceof ReflectionProperty && $prop->getColumnName() === $columnName) {
                    $property = $prop;

                    break;
                }
            }

            if ($property === null) {
                continue; // Skip if property not found or is a relation
            }

            $setParts[] = "{$columnName} = ?";
            $values[] = $this->normalizeForDatabase($newValue);
        }

        if (empty($setParts)) {
            return null; // No non-relation properties changed
        }

        // Handle MorphTo relationships - add any morph columns that changed
        $this->addMorphToChanges($entity, $setParts, $values);

        // Handle ManyToOne and owning OneToOne relationships
        $this->addManyToOneChanges($entity, $setParts, $values);

        // Optimistic-lock bump: server-side increment, no bound parameter needed.
        foreach ($versionColumns as $versionColumn) {
            $setParts[] = "{$versionColumn} = {$versionColumn} + 1";
        }

        // Prepare WHERE clause - try primary key first, then fall back to 'id' property
        [$whereClause, $whereValues] = $this->buildWhereClause($entity);

        // Optimistic-lock check: the slice's own #[Version] column, bound to the
        // entity's currently-tracked value (kept in sync with the DB by the
        // deferred reconciliation returned below and by UnitOfWork::clearChanges() refreshing the snapshot).
        $checkedVersionColumns = $versionColumns;
        $originalVersionValues = [];
        foreach ($checkedVersionColumns as $checkedColumn) {
            $originalVersionValues[$checkedColumn] = $this->getVersionColumnValue($metadata, $entity, $checkedColumn);
            $whereClause .= " AND {$checkedColumn} = ?";
            $whereValues[] = $originalVersionValues[$checkedColumn];
        }

        // Combine all parameters
        $allValues = array_merge($values, $whereValues);

        // Generate UPDATE SQL
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $tableName,
            implode(', ', $setParts),
            $whereClause
        );

        $statement = $this->connection->executeQuery($sql, $allValues);

        $this->assertVersionCheckSucceeded($statement, $tableName, $checkedVersionColumns);

        return $this->deferredVersionReconciliation($metadata, $entity, $originalVersionValues);
    }

    /**
     * Builds the in-memory reconciliation for an entity's checked #[Version] columns.
     *
     * The DB already incremented them server-side; the returned bump walks the tracked
     * original values forward by one so the entity matches its row without a re-SELECT.
     * The caller decides when to apply it (EntityManager::flush() does so before
     * post-update callbacks) and reverts it if the flush never commits.
     *
     * @param array<string, mixed> $originalCheckedValues column name => value bound to the UPDATE's WHERE
     * @return DeferredVersionBump|null null when the entity has no checked #[Version] column
     */
    public function deferredVersionReconciliation(EntityMetadata $metadata, object $entity, array $originalCheckedValues): ?DeferredVersionBump
    {
        if ($originalCheckedValues === []) {
            return null;
        }

        return new DeferredVersionBump(function (int $delta) use ($metadata, $entity, $originalCheckedValues): void {
            foreach ($originalCheckedValues as $column => $original) {
                $this->setVersionColumnValue($metadata, $entity, $column, $original + $delta);
            }
        });
    }

    /**
     * Executes an UPDATE operation directly by table name and columns.
     *
     * @param array<string, mixed> $columnChanges
     * @param array<int, mixed> $whereValues
     * @param string[] $versionBumpColumns Columns to bump (SET col = col + 1), never checked
     * @param array<string, mixed> $versionCheckColumns Columns to check (WHERE col = ?), keyed by column name → expected original value
     */
    public function executeUpdateByTable(
        string $tableName,
        array $columnChanges,
        string $whereClause,
        array $whereValues,
        array $versionBumpColumns = [],
        array $versionCheckColumns = [],
    ): void {
        if (empty($columnChanges) && empty($versionBumpColumns)) {
            return;
        }

        $setParts = [];
        $values = [];
        foreach ($columnChanges as $columnName => $value) {
            $setParts[] = "{$columnName} = ?";
            $values[] = $value;
        }

        foreach ($versionBumpColumns as $versionColumn) {
            $setParts[] = "{$versionColumn} = {$versionColumn} + 1";
        }

        foreach ($versionCheckColumns as $checkedColumn => $originalValue) {
            $whereClause .= " AND {$checkedColumn} = ?";
            $whereValues[] = $originalValue;
        }

        $allValues = array_merge($values, $whereValues);

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $tableName,
            implode(', ', $setParts),
            $whereClause
        );

        $statement = $this->connection->executeQuery($sql, $allValues);

        $this->assertVersionCheckSucceeded($statement, $tableName, array_keys($versionCheckColumns));
    }

    /**
     * @param string[] $checkedVersionColumns
     */
    private function assertVersionCheckSucceeded(\PDOStatement $statement, string $tableName, array $checkedVersionColumns): void
    {
        if (empty($checkedVersionColumns) || $statement->rowCount() > 0) {
            return;
        }

        throw new OptimisticLockException(sprintf(
            'Optimistic lock failed updating "%s": row not found or version mismatch on %s.',
            $tableName,
            implode(', ', $checkedVersionColumns)
        ));
    }

    public function getVersionColumnValue(EntityMetadata $metadata, object $entity, string $columnName): mixed
    {
        $propertyName = $metadata->getPropertyNameForColumn($columnName);
        $property = $propertyName !== null ? $metadata->getProperty($propertyName) : null;

        return $property?->getValue($entity);
    }

    private function setVersionColumnValue(EntityMetadata $metadata, object $entity, string $columnName, mixed $value): void
    {
        $propertyName = $metadata->getPropertyNameForColumn($columnName);
        $property = $propertyName !== null ? $metadata->getProperty($propertyName) : null;
        $property?->setValue($entity, $value);
    }

    /**
     * Executes a DELETE operation for a single entity.
     */
    public function executeDelete(object $entity): void
    {
        $reflectionEntity = $this->getReflectionEntity($entity::class);
        $tableName = $reflectionEntity->getTableName();

        // Prepare WHERE clause
        [$whereClause, $whereValues] = $this->buildWhereClause($entity);

        // Generate DELETE SQL
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $tableName,
            $whereClause
        );

        $this->connection->executeQuery($sql, $whereValues);
    }

    /**
     * Executes a SELECT query and returns raw results.
     *
     * @param string $sql The SQL query
     * @param array $params Query parameters
     * @return array Raw database results
     */
    public function executeSelect(string $sql, array $params = []): array
    {
        try {
            $statement = $this->connection->executeQuery($sql, $params);
        } catch (\Exception) {
            return [];
        }

        return $statement->fetchAll();
    }

    /**
     * Builds a WHERE clause for identifying an entity.
     *
     * @return array{string, array} [whereClause, whereValues]
     */
    private function buildWhereClause(object $entity): array
    {
        $reflectionEntity = $this->getReflectionEntity($entity::class);
        $whereParts = [];
        $whereValues = [];

        $primaryKeyColumns = $reflectionEntity->getPrimaryKeyColumns();
        if (!empty($primaryKeyColumns)) {
            // Use primary key for WHERE clause
            foreach ($primaryKeyColumns as $pkColumn) {
                // Find the property that maps to this primary key column
                $pkProperty = null;
                foreach (iterator_to_array($reflectionEntity->getEntityProperties()) as $property) {
                    if ($property->getColumnName() === $pkColumn && $property instanceof ReflectionProperty) {
                        $pkProperty = $property;

                        break;
                    }
                }

                if ($pkProperty === null) {
                    throw new \RuntimeException("Primary key column '{$pkColumn}' not found in entity properties");
                }

                $fieldName = $pkProperty->getFieldName();
                $reflectionProperty = new NativeReflectionProperty($entity, $fieldName);
                $reflectionProperty->setAccessible(true);
                $pkValue = $reflectionProperty->getValue($entity);

                $whereParts[] = "{$pkColumn} = ?";
                $whereValues[] = $pkValue;
            }
        } else {
            throw new \RuntimeException('Cannot identify entity for update/delete - no primary key columns defined');
        }

        return [implode(' AND ', $whereParts), $whereValues];
    }

    private function generateNextId(string $entityClass): mixed
    {
        $reflectionEntity = $this->getReflectionEntity($entityClass);

        foreach ($reflectionEntity->getEntityFieldsProperties() as $property) {
            if ($property instanceof ReflectionProperty && $property->isPrimaryKey()) {
                $generatorType = $property->getGeneratorType();

                if ($generatorType !== null) {
                    $generator = $this->generatorRegistry->getGenerator($generatorType);
                    $options = $property->getGeneratorOptions() ?? [];

                    return $generator->generate($entityClass, $options);
                }

                break;
            }
        }

        return null;
    }

    private function setEntityId(object $entity, mixed $id): void
    {
        $primaryKeyProperty = $this->findPrimaryKeyProperty($entity);
        if ($primaryKeyProperty !== null) {
            $primaryKeyProperty->setAccessible(true);
            $primaryKeyProperty->setValue($entity, $id);
        }
    }

    private function extractEntityId(object $entity): mixed
    {
        $primaryKeyProperty = $this->findPrimaryKeyProperty($entity);
        if ($primaryKeyProperty !== null) {
            $primaryKeyProperty->setAccessible(true);

            return $primaryKeyProperty->isInitialized($entity) ? $primaryKeyProperty->getValue($entity) : null;
        }

        return null;
    }

    private function findPrimaryKeyProperty(object $entity): ?NativeReflectionProperty
    {
        $reflectionEntity = $this->getReflectionEntity($entity::class);

        // First try to find primary key from entity metadata
        foreach (iterator_to_array($reflectionEntity->getEntityFieldsProperties()) as $property) {
            if ($property instanceof ReflectionProperty && $property->isPrimaryKey()) {
                return ReflectionCache::getProperty($entity::class, $property->getFieldName());
            }
        }

        // Treat 'id' property as implicit primary key
        $reflection = ReflectionCache::getClass($entity::class);
        if ($reflection->hasProperty('id')) {
            return ReflectionCache::getProperty($entity::class, 'id');
        }

        return null;
    }

    /**
     * Add MorphTo relationship columns to the insert/update operation.
     */
    private function addMorphToColumns(object $entity, array &$columns, array &$placeholders, array &$values): void
    {
        $entityMetadata = $this->entityMetadataCache[$entity::class] ??= new EntityMetadata($entity::class);

        foreach ($entityMetadata->getColumnRelations() as $relation) {
            if ($relation->isMorphTo()) {
                // Get the relationship value
                $propertyName = $relation->getPropertyName();
                $reflectionProperty = ReflectionCache::getProperty($entity::class, $propertyName);
                $reflectionProperty->setAccessible(true);
                $relatedEntity = $reflectionProperty->getValue($entity);

                if ($relatedEntity !== null) {
                    // Extract type and ID from the related entity
                    $morphType = $relatedEntity::class;
                    $relatedId = $this->extractEntityId($relatedEntity);

                    // Add the morph columns
                    $columns[] = $relation->getMorphTypeColumnName();
                    $placeholders[] = '?';
                    $values[] = $morphType;

                    $columns[] = $relation->getMorphIdColumnName();
                    $placeholders[] = '?';
                    $values[] = $relatedId;
                }
            }
        }
    }

    /**
     * Add MorphTo relationship changes to the update operation.
     */
    private function addMorphToChanges(object $entity, array &$setParts, array &$values): void
    {
        $entityMetadata = $this->entityMetadataCache[$entity::class] ??= new EntityMetadata($entity::class);

        foreach ($entityMetadata->getColumnRelations() as $relation) {
            if ($relation->isMorphTo()) {
                // Get the relationship value
                $propertyName = $relation->getPropertyName();
                $reflectionProperty = ReflectionCache::getProperty($entity::class, $propertyName);
                $reflectionProperty->setAccessible(true);
                $relatedEntity = $reflectionProperty->getValue($entity);

                // For updates, we always include morph columns if the relationship is set
                // (in a full implementation, we'd track if the relationship actually changed)
                if ($relatedEntity !== null) {
                    // Extract type and ID from the related entity
                    $morphType = $relatedEntity::class;
                    $relatedId = $this->extractEntityId($relatedEntity);

                    // Add the morph columns
                    $setParts[] = $relation->getMorphTypeColumnName() . ' = ?';
                    $values[] = $morphType;

                    $setParts[] = $relation->getMorphIdColumnName() . ' = ?';
                    $values[] = $relatedId;
                }
            }
        }
    }

    private function addManyToOneColumns(object $entity, array &$columns, array &$placeholders, array &$values): void
    {
        $entityMetadata = $this->entityMetadataCache[$entity::class] ??= new EntityMetadata($entity::class);

        foreach ($entityMetadata->getColumnRelations() as $relation) {
            if (!$relation->isOwningSide() || !$relation->isForeignKeyRequired() || $relation->isMorphTo()) {
                continue;
            }

            $reflectionProperty = ReflectionCache::getProperty($entity::class, $relation->getPropertyName());
            $reflectionProperty->setAccessible(true);
            $relatedEntity = $reflectionProperty->getValue($entity);

            if ($relatedEntity === null) {
                continue;
            }

            $columns[] = $relation->getColumnName();
            $placeholders[] = '?';
            $values[] = $this->extractEntityId($relatedEntity);
        }
    }

    private function addManyToOneChanges(object $entity, array &$setParts, array &$values): void
    {
        $entityMetadata = $this->entityMetadataCache[$entity::class] ??= new EntityMetadata($entity::class);

        foreach ($entityMetadata->getColumnRelations() as $relation) {
            if (!$relation->isOwningSide() || !$relation->isForeignKeyRequired() || $relation->isMorphTo()) {
                continue;
            }

            $reflectionProperty = ReflectionCache::getProperty($entity::class, $relation->getPropertyName());
            $reflectionProperty->setAccessible(true);
            $relatedEntity = $reflectionProperty->getValue($entity);

            if ($relatedEntity === null) {
                continue;
            }

            $setParts[] = $relation->getColumnName() . ' = ?';
            $values[] = $this->extractEntityId($relatedEntity);
        }
    }

    /**
     * Extract column names and values from an entity for INSERT operations.
     *
     * @return array{columns: string[], values: array}
     */
    public function extractInsertData(object $entity): array
    {
        $reflectionEntity = $this->getReflectionEntity($entity::class);

        $properties = array_filter(
            iterator_to_array($reflectionEntity->getEntityProperties()),
            fn ($property) => $property instanceof ReflectionProperty
        );

        $columns = [];
        $placeholders = [];
        $values = [];

        foreach ($properties as $property) {
            $columnName = $property->getColumnName();
            $fieldName = $property->getFieldName();

            $reflectionProperty = new NativeReflectionProperty($entity, $fieldName);
            $reflectionProperty->setAccessible(true);
            $value = $reflectionProperty->isInitialized($entity) ? $reflectionProperty->getValue($entity) : null;

            if ($property->isPrimaryKey() && $value === null) {
                continue;
            }

            if ($value === null && !$property->isNullable() && $property->getDefaultValue() === null) {
                continue;
            }

            $columns[] = $columnName;
            $placeholders[] = '?';
            $values[] = $this->normalizeForDatabase($value);
        }

        $this->addMorphToColumns($entity, $columns, $placeholders, $values);

        return ['columns' => $columns, 'values' => $values];
    }

    /**
     * Convert a PHP value to its database-bindable scalar representation.
     * Backed enums → backing value; pure enums → case name; everything else pass-through.
     */
    private function normalizeForDatabase(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    /**
     * Build WHERE clause for an entity based on its primary key.
     *
     * @return array{clause: string, values: array}
     */
    public function buildEntityWhereClause(object $entity): array
    {
        [$clause, $values] = $this->buildWhereClause($entity);

        return ['clause' => $clause, 'values' => $values];
    }

    /**
     * Sync owning-side M2M pivot rows for an entity using granular INSERT/UPDATE/DELETE.
     *
     * MappingCollection: three-pass diff — DELETE removed items, INSERT new items, UPDATE dirty pivot.
     * Plain Collection:  DELETE-all then INSERT-all (no pivot data to diff).
     *
     * @param bool $dirtyOnly Skip collections that haven't changed since last flush
     */
    public function syncManyToMany(object $entity, bool $dirtyOnly = false): void
    {
        $metadata = $this->entityMetadataCache[$entity::class] ??= new EntityMetadata($entity::class);

        foreach ($metadata->getRelations() as $propName => $relation) {
            if (
                (!$relation instanceof ReflectionManyToMany && !$relation instanceof ReflectionMorphToMany)
                || !$relation->isOwningSide()
            ) {
                continue;
            }

            $entityProp = ReflectionCache::getProperty($entity::class, $propName);
            $entityProp->setAccessible(true);

            if (!$entityProp->isInitialized($entity)) {
                continue;
            }

            $value = $entityProp->getValue($entity);

            if ($value === null || is_array($value)) {
                continue;
            }

            if ($dirtyOnly && !$value->isDirty()) {
                continue;
            }

            [, $ownerPkValues] = $this->buildWhereClause($entity);
            $ownerPk = $ownerPkValues[0];

            $isMorphToMany = $relation instanceof ReflectionMorphToMany;

            $pivotTable = $isMorphToMany
                ? $relation->getTableName()
                : $relation->getPivotTableName();
            $foreignKey = $isMorphToMany
                ? $relation->getOwnerJoinColumn()
                : $relation->getForeignPivotKey();
            $relatedKey = $isMorphToMany
                ? $relation->getTargetJoinColumn()
                : $relation->getRelatedPivotKey();
            $typeColumn = $isMorphToMany ? $relation->getTypeColumn() : null;
            $typeValue = $isMorphToMany ? MorphTypeRegistry::getAlias($entity::class) : null;

            if ($value instanceof MappingCollection) {
                $this->syncMappingCollection(
                    $value,
                    $pivotTable,
                    $foreignKey,
                    $relatedKey,
                    $ownerPk,
                    $relation,
                    $typeColumn,
                    $typeValue
                );
            } else {
                $this->syncPlainCollection(
                    $value,
                    $pivotTable,
                    $foreignKey,
                    $relatedKey,
                    $ownerPk,
                    $relation,
                    $typeColumn,
                    $typeValue
                );
            }

            $value->markClean();
        }
    }

    private function syncMappingCollection(
        MappingCollection $collection,
        string $pivotTable,
        string $foreignKey,
        string $relatedKey,
        mixed $ownerPk,
        ReflectionManyToMany|ReflectionMorphToMany $relation,
        ?string $typeColumn = null,
        ?string $typeValue = null,
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        [$createdAtCol, $updatedAtCol] = $this->resolveTimestampColumns($relation);

        // 1. DELETE removed items
        foreach ($collection->getRemovedItems() as $item) {
            [, $relatedPkValues] = $this->buildWhereClause($item->entity);
            $whereParts = ["{$foreignKey} = ?", "{$relatedKey} = ?"];
            $whereValues = [$ownerPk, $relatedPkValues[0]];
            if ($typeColumn !== null) {
                $whereParts[] = "{$typeColumn} = ?";
                $whereValues[] = $typeValue;
            }
            $this->connection->executeQuery(
                "DELETE FROM {$pivotTable} WHERE " . implode(' AND ', $whereParts),
                $whereValues
            );
        }

        // 2. INSERT new items
        $autoTimestampCols = array_filter([$createdAtCol, $updatedAtCol]);
        foreach ($collection->getNewItems() as $item) {
            [, $relatedPkValues] = $this->buildWhereClause($item->entity);

            $pivotData = array_diff_key($item->pivot(), array_flip($autoTimestampCols));
            $columns = [$foreignKey, $relatedKey];
            $values = [$ownerPk, $relatedPkValues[0]];
            if ($typeColumn !== null) {
                $columns[] = $typeColumn;
                $values[] = $typeValue;
            }
            $columns = array_merge($columns, array_keys($pivotData));
            $values = array_merge($values, array_values($pivotData));

            if ($createdAtCol !== null) {
                $columns[] = $createdAtCol;
                $values[] = $now;
            }
            if ($updatedAtCol !== null) {
                $columns[] = $updatedAtCol;
                $values[] = $now;
            }

            $this->connection->executeQuery(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $pivotTable,
                    implode(', ', $columns),
                    implode(', ', array_fill(0, count($values), '?'))
                ),
                $values
            );
        }

        // 3. UPDATE dirty pivot items (only changed columns + updatedAt)
        foreach ($collection->getDirtyItems() as $item) {
            [, $relatedPkValues] = $this->buildWhereClause($item->entity);

            $changes = array_diff_key($item->getPivotChanges(), array_flip(array_filter([$createdAtCol])));
            if ($updatedAtCol !== null) {
                $changes[$updatedAtCol] = $now;
            }

            if (empty($changes)) {
                continue;
            }

            $setParts = array_map(fn (string $col) => "{$col} = ?", array_keys($changes));
            $whereParts = ["$foreignKey = ?", "$relatedKey = ?"];
            $whereValues = [$ownerPk, $relatedPkValues[0]];
            if ($typeColumn !== null) {
                $whereParts[] = "$typeColumn = ?";
                $whereValues[] = $typeValue;
            }

            $this->connection->executeQuery(
                sprintf(
                    'UPDATE %s SET %s WHERE %s',
                    $pivotTable,
                    implode(', ', $setParts),
                    implode(' AND ', $whereParts)
                ),
                [...array_values($changes), ...$whereValues]
            );
        }
    }

    private function syncPlainCollection(
        Collection $collection,
        string $pivotTable,
        string $foreignKey,
        string $relatedKey,
        mixed $ownerPk,
        ReflectionManyToMany|ReflectionMorphToMany $relation,
        ?string $typeColumn = null,
        ?string $typeValue = null,
    ): void {
        [$createdAtCol, $updatedAtCol] = $this->resolveTimestampColumns($relation);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($collection->getRemovedItems() as $entity) {
            [, $relatedPkValues] = $this->buildWhereClause($entity);
            $whereParts = ["$foreignKey = ?", "$relatedKey = ?"];
            $whereValues = [$ownerPk, $relatedPkValues[0]];
            if ($typeColumn !== null) {
                $whereParts[] = "$typeColumn = ?";
                $whereValues[] = $typeValue;
            }
            $this->connection->executeQuery(
                "DELETE FROM {$pivotTable} WHERE " . implode(' AND ', $whereParts),
                $whereValues
            );
        }

        foreach ($collection->getAddedItems() as $entity) {
            [, $relatedPkValues] = $this->buildWhereClause($entity);

            $columns = [$foreignKey, $relatedKey];
            $values = [$ownerPk, $relatedPkValues[0]];
            if ($typeColumn !== null) {
                $columns[] = $typeColumn;
                $values[] = $typeValue;
            }

            if ($createdAtCol !== null) {
                $columns[] = $createdAtCol;
                $values[] = $now;
            }
            if ($updatedAtCol !== null) {
                $columns[] = $updatedAtCol;
                $values[] = $now;
            }

            $this->connection->executeQuery(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $pivotTable,
                    implode(', ', $columns),
                    implode(', ', array_fill(0, count($values), '?'))
                ),
                $values
            );
        }
    }

    /** @return array{?string, ?string} [$createdAtCol, $updatedAtCol] */
    private function resolveTimestampColumns(ReflectionManyToMany|ReflectionMorphToMany $relation): array
    {
        $createdAtCol = null;
        $updatedAtCol = null;
        foreach ($relation->getExtraProperties() as $extraProp) {
            if ($extraProp->createdAt) {
                $createdAtCol = $extraProp->name;
            }
            if ($extraProp->updatedAt) {
                $updatedAtCol = $extraProp->name;
            }
        }

        return [$createdAtCol, $updatedAtCol];
    }

    /**
     * Delete all owning-side pivot rows for an entity before its row is deleted.
     * Prevents FK violations when there is no CASCADE DELETE on the pivot table.
     */
    public function deletePivotRows(object $entity): void
    {
        $metadata = $this->entityMetadataCache[$entity::class] ??= new EntityMetadata($entity::class);

        foreach ($metadata->getRelations() as $relation) {
            if (
                (!$relation instanceof ReflectionManyToMany && !$relation instanceof ReflectionMorphToMany)
                || !$relation->isOwningSide()
            ) {
                continue;
            }

            [, $ownerPkValues] = $this->buildWhereClause($entity);

            if ($relation instanceof ReflectionMorphToMany) {
                $this->connection->executeQuery(
                    sprintf(
                        'DELETE FROM %s WHERE %s = ? AND %s = ?',
                        $relation->getTableName(),
                        $relation->getOwnerJoinColumn(),
                        $relation->getTypeColumn()
                    ),
                    [$ownerPkValues[0], MorphTypeRegistry::getAlias($entity::class)]
                );

                continue;
            }

            $this->connection->executeQuery(
                sprintf('DELETE FROM %s WHERE %s = ?', $relation->getPivotTableName(), $relation->getForeignPivotKey()),
                [$ownerPkValues[0]]
            );
        }
    }
}
