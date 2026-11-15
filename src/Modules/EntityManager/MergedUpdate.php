<?php

namespace Articulate\Modules\EntityManager;

final readonly class MergedUpdate {
    /**
     * @param array<string, mixed> $set
     * @param array<int, mixed> $whereValues
     */
    public function __construct(
        public string $table,
        public ?object $entity,
        public array $set,
        public string $where,
        public array $whereValues,
    ) {
    }
}
