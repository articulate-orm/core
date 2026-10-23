<?php

namespace Articulate\Modules\EntityManager;

final readonly class EntityUpdate {
    /**
     * @param array<string, mixed> $changes
     */
    public function __construct(
        public object $entity,
        public array $changes,
    ) {
    }
}
