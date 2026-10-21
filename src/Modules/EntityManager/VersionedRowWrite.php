<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Connection;
use Articulate\Exceptions\OptimisticLockException;
use Articulate\Schema\EntityMetadata;

/**
 * Owns the versioned row write: an entity update (UPDATE or soft-delete) that
 * bumps (SET v = v + 1) and checks (WHERE v = ?) the slice's own #[Version]
 * column. Zero matched rows is a lost update (OptimisticLockException).
 *
 * Bump and check come only from the acting entity's own metadata. The returned
 * handle defers the in-memory property change: EntityManager::flush() commits
 * every handle once the whole batch passed (before post-update callbacks) and
 * abandons them if the flush fails, so a mid-flush conflict never strands a
 * bumped property. A caller-owned transaction rolled back after flush() returned
 * still strands the bump (docs/version-bump-caller-transaction-proposal.md).
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
        string $tableName,
        EntityMetadata $metadata,
        object $entity,
        array $setParts,
        array $setValues,
        string $whereClause,
        array $whereValues,
    ): VersionedRowWriteHandle {
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

        if ($versionColumns !== [] && $statement->rowCount() === 0) {
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
