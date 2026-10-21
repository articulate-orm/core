<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Schema\EntityMetadataRegistry;

interface UpdateConflictResolutionStrategy {
    /**
     * @param array<int, array{entity: object, changes: array}> $updates
     * @return array<int, array{entity: object, changes: array}|array{table: string, entity: object, set: array<string, mixed>, where: string, whereValues: array<int, mixed>}>
     */
    public function resolve(array $updates, EntityMetadataRegistry $metadataRegistry): array;
}
