<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Schema\EntityMetadata;
use Articulate\Schema\EntityMetadataRegistry;

class MergeUpdateConflictResolutionStrategy implements UpdateConflictResolutionStrategy {
    public function resolve(array $updates, EntityMetadataRegistry $metadataRegistry): array
    {
        $result = [];
        $combinedIndexes = [];

        foreach ($updates as $update) {
            if (isset($update['table'])) {
                $result[] = $update;

                continue;
            }

            $entity = $update['entity'];
            $metadata = $metadataRegistry->getMetadata($entity::class);

            if (!$this->canCombineUpdate($metadata)) {
                $result[] = $update;

                continue;
            }

            $identity = $this->buildUpdateIdentity($entity, $metadata);
            if ($identity === null) {
                $result[] = $update;

                continue;
            }

            [$whereClause, $whereValues, $identityKey] = $identity;
            $columnChanges = $this->mapChangesToColumns($update['changes'], $metadata);
            $versionBumpColumns = $metadata->getVersionColumns();

            if ($columnChanges === [] && $versionBumpColumns === []) {
                $result[] = $update;

                continue;
            }

            $tableName = $metadata->getTableName();
            $groupKey = $tableName . '|' . $identityKey;

            if (!isset($combinedIndexes[$groupKey])) {
                $combinedIndexes[$groupKey] = count($result);
                $result[] = [
                    'table' => $tableName,
                    'entity' => $entity,
                    'set' => $columnChanges,
                    'where' => $whereClause,
                    'whereValues' => $whereValues,
                    'versionBumpColumns' => $versionBumpColumns,
                ];

                continue;
            }

            $index = $combinedIndexes[$groupKey];
            $result[$index]['set'] = array_merge($result[$index]['set'], $columnChanges);
            // Two siblings may both bump the same raw column (design explicitly allows this) —
            // dedupe by column name, else array_merge would emit "col = col + 1" twice and
            // double-increment it in a single flush.
            $result[$index]['versionBumpColumns'] = array_values(array_unique(array_merge(
                $result[$index]['versionBumpColumns'],
                $versionBumpColumns
            )));
        }

        return $result;
    }

    private function canCombineUpdate(EntityMetadata $metadata): bool
    {
        // A #[Version]-checking entity's WHERE version = ? check and rowCount()-based
        // conflict detection must run on its own entity-bound UPDATE — combining it
        // into a table-scoped merge would silently drop that guarantee.
        if ($metadata->getVersionColumns() !== []) {
            return false;
        }

        foreach ($metadata->getColumnRelations() as $relation) {
            // MorphTo stores two columns (type + id) that must change atomically.
            // Merging them as independent column changes across UoWs risks writing
            // a mismatched type/id pair, so entities with morph relations cannot be combined.
            if ($relation->isMorphTo()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{string, array<int, mixed>, string}|null
     */
    private function buildUpdateIdentity(object $entity, EntityMetadata $metadata): ?array
    {
        $primaryKeyColumns = $metadata->getPrimaryKeyColumns();

        if (!empty($primaryKeyColumns)) {
            $identityParts = [];
            $whereParts = [];
            $whereValues = [];

            foreach ($primaryKeyColumns as $columnName) {
                $propertyName = $metadata->getPropertyNameForColumn($columnName);
                if ($propertyName === null) {
                    return null;
                }

                $property = $metadata->getProperty($propertyName);
                if ($property === null) {
                    return null;
                }

                $value = $property->getValue($entity);
                if ($value === null) {
                    return null;
                }

                $identityParts[] = $columnName . '=' . $value;
                $whereParts[] = "{$columnName} = ?";
                $whereValues[] = $value;
            }

            return [implode(' AND ', $whereParts), $whereValues, implode('|', $identityParts)];
        }

        $idProperty = $metadata->getProperty('id');
        if ($idProperty === null) {
            return null;
        }

        $idValue = $idProperty->getValue($entity);
        if ($idValue === null) {
            return null;
        }

        $columnName = $idProperty->getColumnName();
        $whereClause = "{$columnName} = ?";

        return [$whereClause, [$idValue], $columnName . '=' . $idValue];
    }

    /**
     * @param array<string, mixed> $changes  Changes keyed by column name
     * @return array<string, mixed>
     */
    private function mapChangesToColumns(array $changes, EntityMetadata $metadata): array
    {
        $columnChanges = [];

        foreach ($changes as $columnName => $value) {
            // Changes are already keyed by column name; validate the column is known.
            if ($metadata->getPropertyNameForColumn($columnName) !== null) {
                $columnChanges[$columnName] = $value;
            }
        }

        return $columnChanges;
    }
}
