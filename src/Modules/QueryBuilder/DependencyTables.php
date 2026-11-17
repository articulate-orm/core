<?php

namespace Articulate\Modules\QueryBuilder;

/**
 * The tables besides its root that a cacheable query reads from. Each one's
 * generation is folded into the query's cache key, so a unit-of-work write
 * to any of them invalidates the cached id-list.
 */
class DependencyTables {
    /** @var array<string, true> */
    private array $tables = [];

    public function add(string $table): void
    {
        $this->tables[$table] = true;
    }

    // join() takes "table alias" as one string; only the real table has a generation
    public function addJoinTarget(string $tableWithOptionalAlias): void
    {
        $this->add(preg_split('/\s+/', trim($tableWithOptionalAlias), 2)[0]);
    }

    public function clear(): void
    {
        $this->tables = [];
    }

    /**
     * @return list<string> the root plus every dependency table, deduplicated and sorted
     */
    public function withRoot(string $rootTable): array
    {
        $tables = array_keys([$rootTable => true] + $this->tables);
        sort($tables);

        return $tables;
    }
}
