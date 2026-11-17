<?php

namespace Articulate\Modules\QueryBuilder;

/**
 * The tables besides its root that a cacheable query reads from. Each one's
 * generation is folded into the query's cache key, so a unit-of-work write
 * to any of them invalidates the cached id-list. Kept in buckets so
 * resetJoins() can drop the join tables alone.
 */
class DependencyTables {
    /** @var array<string, true> */
    private array $joins = [];

    /** @var array<string, true> */
    private array $subqueries = [];

    /** @var array<string, true> declared via cacheable(dependsOn:) */
    private array $declared = [];

    // a where-group's subqueries are read by the outer query, so the group records them there
    public function forGroup(): self
    {
        $group = new self();
        $group->subqueries = &$this->subqueries;

        return $group;
    }

    public function addJoin(string $tableReference): void
    {
        $this->joins[TableReference::table($tableReference)] = true;
    }

    public function addDeclared(string $table): void
    {
        $this->declared[$table] = true;
    }

    // everything the subquery reads, its own FROM and dependsOn tables included
    public function addSubquery(string $fromReference, self $subquery): void
    {
        if ($fromReference !== '') {
            $this->subqueries[TableReference::table($fromReference)] = true;
        }
        $this->subqueries += $subquery->all();
    }

    public function resetJoins(): void
    {
        $this->joins = [];
    }

    public function reset(): void
    {
        $this->joins = [];
        $this->subqueries = [];
        $this->declared = [];
    }

    /**
     * @return list<string> the root plus every dependency table, each once
     */
    public function withRoot(string $rootTable): array
    {
        return array_map('strval', array_keys([$rootTable => true] + $this->all()));
    }

    /**
     * @return array<string, true>
     */
    private function all(): array
    {
        return $this->joins + $this->subqueries + $this->declared;
    }
}
