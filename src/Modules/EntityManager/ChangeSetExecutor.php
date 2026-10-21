<?php

namespace Articulate\Modules\EntityManager;

class ChangeSetExecutor {
    public function __construct(
        private readonly QueryExecutor $queryExecutor,
        private readonly EntityDependencySorter $dependencySorter,
    ) {
    }

    /**
     * @param array{inserts: object[], updates: list<EntityUpdate|MergedUpdate>, deletes: object[], softDeletes: object[]} $changes
     * @return list<VersionedRowWriteHandle> see EntityManager::flush()
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

        $handles = [];

        $updatedEntities = [];
        foreach ($changes['updates'] as $update) {
            if ($update instanceof MergedUpdate) {
                $this->queryExecutor->executeUpdateByTable(
                    tableName: $update->table,
                    columnChanges: $update->set,
                    whereClause: $update->where,
                    whereValues: $update->whereValues,
                );

                continue;
            }

            $handle = $this->queryExecutor->executeUpdate($update->entity, $update->changes);
            if ($handle !== null) {
                $handles[] = $handle;
            }
            $updatedEntities[] = $update->entity;
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
            $handle = $this->queryExecutor->executeSoftDelete($entity);
            if ($handle !== null) {
                $handles[] = $handle;
            }
        }

        return $handles;
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
}
