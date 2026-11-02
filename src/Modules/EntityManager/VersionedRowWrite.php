<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Connection;
use Articulate\Exceptions\OptimisticLockException;
use Articulate\Schema\EntityMetadata;

/**
 * Bump and check come only from the acting entity's own metadata. The in-memory
 * property change is deferred to the handle because a mid-flush conflict must not
 * strand a bumped property. A caller-owned transaction rolled back after flush()
 * returned still strands the bump.
 */
final class VersionedRowWrite {
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param string[] $setParts SET fragments for the entity's own changes
     * @param array<int, mixed> $setValues bound values of $setParts
     * @param array<int, mixed> $whereValues bound values of $whereClause
     */
    public function execute(
        EntityMetadata $metadata,
        object $entity,
        array $setParts,
        array $setValues,
        string $whereClause,
        array $whereValues,
    ): ?VersionedRowWriteHandle {
        $tableName = $metadata->getTableName();
        $versionColumns = $metadata->getVersionColumns();
        $originalValues = [];

        foreach ($versionColumns as $column) {
            $setParts[] = "{$column} = {$column} + 1";
            $originalValues[$column] = $this->readVersion($metadata, $entity, $column);
            $whereClause .= " AND {$column} = ?";
            $whereValues[] = $originalValues[$column];
        }

        $statement = $this->connection->executeQuery(
            sprintf('UPDATE %s SET %s WHERE %s', $tableName, implode(', ', $setParts), $whereClause),
            array_merge($setValues, $whereValues)
        );

        if ($versionColumns === []) {
            return null;
        }

        if ($statement->rowCount() === 0) {
            throw new OptimisticLockException(sprintf(
                'Optimistic lock failed updating "%s": row not found or version mismatch on %s.',
                $tableName,
                implode(', ', $versionColumns)
            ));
        }

        return new VersionedRowWriteHandle(function (int $delta) use ($metadata, $entity, $originalValues): void {
            foreach ($originalValues as $column => $original) {
                $this->writeVersion($metadata, $entity, $column, $original + $delta);
            }
        });
    }

    private function readVersion(EntityMetadata $metadata, object $entity, string $column): mixed
    {
        $propertyName = $metadata->getPropertyNameForColumn($column);

        return $propertyName !== null ? $metadata->getProperty($propertyName)?->getValue($entity) : null;
    }

    private function writeVersion(EntityMetadata $metadata, object $entity, string $column, mixed $value): void
    {
        $propertyName = $metadata->getPropertyNameForColumn($column);
        if ($propertyName !== null) {
            $metadata->getProperty($propertyName)?->setValue($entity, $value);
        }
    }
}
