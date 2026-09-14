<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Schema\EntityMetadataRegistry;

class ChangeSetExecutor {
    public function __construct(
        private readonly QueryExecutor $queryExecutor,
        private readonly EntityMetadataRegistry $metadataRegistry,
        private readonly EntityDependencySorter $dependencySorter,
    ) {
    }

    /**
     * @param array{inserts: object[], updates: array<int, array{entity?: object, changes?: array, table?: string, set?: array, where?: string, whereValues?: array, versionBumpColumns?: string[]}>, deletes: object[], softDeletes: object[]} $changes
     * @return list<DeferredVersionBump> In-memory #[Version] reconciliations; see EntityManager::flush().
     */
    public function execute(array $changes): array
    {
        $orderedInserts = $this->dependencySorter->order($changes['inserts'], 'insert');
        foreach ($orderedInserts as $entity) {
            $this->queryExecutor->executeInsert($entity);
        }

        foreach ($orderedInserts as $entity) {
            $this->queryExecutor->syncManyToMany($entity);
        }

        $deferredVersionBumps = [];

        $updatedEntities = [];
        foreach ($changes['updates'] as $update) {
            if (isset($update['table'])) {
                $this->queryExecutor->executeUpdateByTable(
                    tableName: $update['table'],
                    columnChanges: $update['set'],
                    whereClause: $update['where'],
                    whereValues: $update['whereValues'],
                    versionBumpColumns: $update['versionBumpColumns'] ?? [],
                );

                continue;
            }

            $bump = $this->queryExecutor->executeUpdate($update['entity'], $update['changes']);
            if ($bump !== null) {
                $deferredVersionBumps[] = $bump;
            }
            $updatedEntities[] = $update['entity'];
        }

        foreach ($updatedEntities as $entity) {
            $this->queryExecutor->syncManyToMany($entity);
        }

        $orderedDeletes = $this->dependencySorter->order($changes['deletes'], 'delete');
        foreach ($orderedDeletes as $entity) {
            $this->queryExecutor->deletePivotRows($entity);
            $this->queryExecutor->executeDelete($entity);
        }

        foreach ($changes['softDeletes'] as $entity) {
            $bump = $this->executeSoftDelete($entity);
            if ($bump !== null) {
                $deferredVersionBumps[] = $bump;
            }
        }

        return $deferredVersionBumps;
    }

    /**
     * @param UnitOfWork[] $unitOfWorks
     */
    public function syncManagedManyToMany(array $unitOfWorks): void
    {
        foreach ($unitOfWorks as $unitOfWork) {
            foreach ($unitOfWork->getManagedEntities() as $entity) {
                $this->queryExecutor->syncManyToMany($entity, dirtyOnly: true);
            }
        }
    }

    /**
     * @return DeferredVersionBump|null In-memory #[Version] reconciliation; see EntityManager::flush().
     */
    private function executeSoftDelete(object $entity): ?DeferredVersionBump
    {
        $metadata = $this->metadataRegistry->getMetadata($entity::class);
        $softDeleteColumn = $metadata->getSoftDeleteColumn();

        if ($softDeleteColumn === null) {
            return null;
        }

        $where = $this->queryExecutor->buildEntityWhereClause($entity);

        $versionCheckColumns = [];
        foreach ($metadata->getVersionColumns() as $checkedColumn) {
            $versionCheckColumns[$checkedColumn] = $this->queryExecutor->getVersionColumnValue($metadata, $entity, $checkedColumn);
        }

        $this->queryExecutor->executeUpdateByTable(
            $metadata->getTableName(),
            [$softDeleteColumn => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            $where['clause'],
            $where['values'],
            $metadata->getVersionColumns(),
            $versionCheckColumns,
        );

        return $this->queryExecutor->deferredVersionReconciliation($metadata, $entity, $versionCheckColumns);
    }
}
