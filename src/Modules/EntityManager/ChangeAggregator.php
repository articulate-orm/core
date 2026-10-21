<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Exceptions\UpdateConflictException;
use Articulate\Schema\EntityMetadataRegistry;
use Psr\Log\LoggerInterface;

/**
 * Aggregates and optimizes changes from multiple UnitOfWorks.
 *
 * This class collects entity changes from different UnitOfWorks and creates
 * an optimized execution plan that minimizes database queries by merging
 * compatible operations.
 */
class ChangeAggregator {
    /** @var array<string, array{insert?: object[], update?: array{entity: object, changes: array}[], delete?: object[], softDelete?: object[]}> */
    private array $aggregatedChanges = [];

    public function __construct(
        private readonly EntityMetadataRegistry $metadataRegistry,
        private UpdateConflictResolutionStrategy $updateConflictResolutionStrategy,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Aggregates changes from multiple UnitOfWorks into an optimized execution plan.
     *
     * @param UnitOfWork[] $unitOfWorks
     * @return array{inserts: object[], updates: list<EntityUpdate|MergedUpdate>, deletes: object[], softDeletes: object[]}
     */
    public function aggregateChanges(array $unitOfWorks): array
    {
        $this->aggregatedChanges = [];

        foreach ($unitOfWorks as $unitOfWork) {
            $this->collectChangesFromUnitOfWork($unitOfWork);
        }

        $deletes = $this->optimizeDeletes();

        return [
            'inserts' => $this->optimizeInserts(),
            'updates' => $this->toFlushUpdates($this->updateConflictResolutionStrategy->resolve(
                $this->optimizeUpdates(),
                $this->metadataRegistry,
            )),
            'deletes' => $deletes,
            'softDeletes' => $this->optimizeSoftDeletes(),
        ];
    }

    public function setUpdateConflictResolutionStrategy(UpdateConflictResolutionStrategy $updateConflictResolutionStrategy): void
    {
        $this->updateConflictResolutionStrategy = $updateConflictResolutionStrategy;
    }

    /**
     * @param array<int, mixed> $resolved
     * @return list<EntityUpdate|MergedUpdate>
     */
    private function toFlushUpdates(array $resolved): array
    {
        $updates = [];
        foreach ($resolved as $update) {
            $updates[] = $this->toFlushUpdate($update);
        }

        return $updates;
    }

    private function toFlushUpdate(mixed $update): EntityUpdate|MergedUpdate
    {
        if (
            is_array($update)
            && is_string($update['table'] ?? null)
            && (!isset($update['entity']) || is_object($update['entity']))
            && is_array($update['set'] ?? null)
            && is_string($update['where'] ?? null)
            && is_array($update['whereValues'] ?? null)
        ) {
            return new MergedUpdate(
                table: $update['table'],
                entity: $update['entity'] ?? null,
                set: $update['set'],
                where: $update['where'],
                whereValues: $update['whereValues'],
            );
        }

        if (
            is_array($update)
            && !isset($update['table'])
            && is_object($update['entity'] ?? null)
            && is_array($update['changes'] ?? null)
        ) {
            return new EntityUpdate($update['entity'], $update['changes']);
        }

        throw UpdateConflictException::malformedStrategyOutput($this->updateConflictResolutionStrategy::class);
    }

    /**
     * Collects changes from a single UnitOfWork and groups them by entity class.
     */
    private function collectChangesFromUnitOfWork(UnitOfWork $unitOfWork): void
    {
        $changeSets = $unitOfWork->getChangeSets();

        // Group by entity class
        foreach ($changeSets['inserts'] as $entity) {
            $class = $entity::class;
            $this->aggregatedChanges[$class]['insert'][] = $entity;
        }

        foreach ($changeSets['updates'] as $update) {
            $class = $update['entity']::class;
            $this->aggregatedChanges[$class]['update'][] = $update;
        }

        foreach ($changeSets['deletes'] as $entity) {
            $class = $entity::class;
            $this->aggregatedChanges[$class]['delete'][] = $entity;
        }

        foreach ($changeSets['softDeletes'] as $entity) {
            $class = $entity::class;
            $this->aggregatedChanges[$class]['softDelete'][] = $entity;
        }
    }

    /**
     * Optimizes insert operations.
     * Currently just returns them as-is, but could be extended for batching.
     *
     * @return object[]
     */
    private function optimizeInserts(): array
    {
        $inserts = [];
        foreach ($this->aggregatedChanges as $classChanges) {
            if (isset($classChanges['insert'])) {
                $inserts = array_merge($inserts, $classChanges['insert']);
            }
        }

        return $inserts;
    }

    /**
     * Optimizes update operations by merging changes to the same entity.
     *
     * @return array{entity: object, changes: array}[]
     */
    private function optimizeUpdates(): array
    {
        $updates = [];

        foreach ($this->aggregatedChanges as $class => $classChanges) {
            if (!isset($classChanges['update'])) {
                continue;
            }

            // Group updates by entity identity
            $updatesByEntity = [];
            foreach ($classChanges['update'] as $update) {
                $entity = $update['entity'];
                $entityId = $this->getEntityIdentity($entity);

                if (!isset($updatesByEntity[$entityId])) {
                    $updatesByEntity[$entityId] = [
                        'entity' => $entity,
                        'changes' => [],
                    ];
                }

                // Merge changes - later changes override earlier ones
                $overwritten = array_keys(array_intersect_key($updatesByEntity[$entityId]['changes'], $update['changes']));
                if (!empty($overwritten)) {
                    $this->logger?->debug('ChangeAggregator: overlapping changes for entity {entity}, overwriting keys: {keys}', [
                        'entity' => $entityId,
                        'keys' => implode(', ', $overwritten),
                    ]);
                }
                $updatesByEntity[$entityId]['changes'] = array_merge(
                    $updatesByEntity[$entityId]['changes'],
                    $update['changes']
                );
            }

            $updates = array_merge($updates, array_values($updatesByEntity));
        }

        return $updates;
    }

    /**
     * Optimizes delete operations.
     * Note: If an entity is both updated and deleted, delete takes precedence.
     *
     * @return object[]
     */
    private function optimizeDeletes(): array
    {
        $deletes = [];

        foreach ($this->aggregatedChanges as $class => $classChanges) {
            if (!isset($classChanges['delete'])) {
                continue;
            }

            // Remove entities that are also being deleted from updates
            $deleteEntities = $classChanges['delete'];
            $updateEntities = $classChanges['update'] ?? [];

            // Filter out entities that are being deleted
            $deleteIdentities = array_map([$this, 'getEntityIdentity'], $deleteEntities);

            if (isset($this->aggregatedChanges[$class]['update'])) {
                $this->aggregatedChanges[$class]['update'] = array_filter(
                    $this->aggregatedChanges[$class]['update'],
                    function ($update) use ($deleteIdentities) {
                        $entityId = $this->getEntityIdentity($update['entity']);

                        return !in_array($entityId, $deleteIdentities, true);
                    }
                );
            }

            $deletes = array_merge($deletes, $deleteEntities);
        }

        return $deletes;
    }

    /**
     * @return object[]
     */
    private function optimizeSoftDeletes(): array
    {
        $softDeletes = [];

        foreach ($this->aggregatedChanges as $classChanges) {
            if (isset($classChanges['softDelete'])) {
                $softDeletes = array_merge($softDeletes, $classChanges['softDelete']);
            }
        }

        return $softDeletes;
    }

    /**
     * Gets a unique identity for an entity based on its primary key.
     */
    private function getEntityIdentity(object $entity): string
    {
        $metadata = $this->metadataRegistry->getMetadata($entity::class);
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();

        if (empty($primaryKeyColumns)) {
            $idProperty = $metadata->getProperty('id');
            $id = $idProperty ? $idProperty->getValue($entity) : null;

            return $entity::class . ':' . ($id ?? spl_object_id($entity));
        }

        // Use primary key values
        $identityParts = [$entity::class];
        foreach ($primaryKeyColumns as $column) {
            $propertyName = $metadata->getPropertyNameForColumn($column);
            $property = $propertyName ? $metadata->getProperty($propertyName) : null;
            $value = $property ? $property->getValue($entity) : null;
            $identityParts[] = $value;
        }

        return implode(':', $identityParts);
    }
}
