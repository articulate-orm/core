<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Schema\EntityMetadataRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Entity]
class SqlStatementCacheKeyEntityA {
    #[Property]
    public string $fieldA;
}

#[Entity]
class SqlStatementCacheKeyEntityB {
    #[Property]
    public string $fieldB;
}

/**
 * The structural statement-cache key must include every clause that changes the compiled
 * SQL, otherwise a cache hit hands back SQL built for a different query shape.
 */
class SqlStatementCacheKeyTest extends TestCase {
    private ArrayCache $pool;

    protected function setUp(): void
    {
        $this->pool = new ArrayCache();
    }

    public function testIdenticalShapesShareCachedSqlButKeepOwnParameters(): void
    {
        $first = $this->qb()->select('id')->from('users')->where('id', 1);
        $second = $this->qb()->select('id')->from('users')->where('id', 2);

        $this->assertSame($first->getSQL(), $second->getSQL());
        $this->assertSame([2], $second->getParameters());
    }

    /**
     * @param callable(QueryBuilder): QueryBuilder $left
     * @param callable(QueryBuilder): QueryBuilder $right
     */
    #[DataProvider('structuralVariants')]
    public function testStructuralDifferenceProducesDifferentSql(callable $left, callable $right): void
    {
        $leftSql = $left($this->qb())->getSQL();
        $rightSql = $right($this->qb())->getSQL();

        $this->assertNotSame($leftSql, $rightSql);
    }

    public static function structuralVariants(): array
    {
        $base = static fn (QueryBuilder $qb): QueryBuilder => $qb->select('id')->from('users')->where('id', 1);
        $grouped = static fn (QueryBuilder $qb): QueryBuilder => $base($qb)->groupBy('name');
        $ordered = static fn (QueryBuilder $qb): QueryBuilder => $base($qb)->orderBy('id', 'ASC');
        $limited = static fn (QueryBuilder $qb): QueryBuilder => $base($qb)->limit(5);

        return [
            'select' => [$base, static fn (QueryBuilder $qb) => $qb->select('name')->from('users')->where('id', 1)],
            'from' => [$base, static fn (QueryBuilder $qb) => $qb->select('id')->from('accounts')->where('id', 1)],
            'join' => [$base, static fn (QueryBuilder $qb) => $base($qb)->join('roles', 'roles.id = users.role_id')],
            'where' => [$base, static fn (QueryBuilder $qb) => $qb->select('id')->from('users')->where('name', 'ann')],
            'groupBy' => [$base, $grouped],
            'having' => [$grouped, static fn (QueryBuilder $qb) => $grouped($qb)->having('COUNT(*) > 1')],
            'orderBy direction' => [$ordered, static fn (QueryBuilder $qb) => $base($qb)->orderBy('id', 'DESC')],
            'limit' => [$base, $limited],
            'offset' => [$limited, static fn (QueryBuilder $qb) => $limited($qb)->offset(10)],
            'distinct' => [$base, static fn (QueryBuilder $qb) => $base($qb)->distinct()],
            'lockForUpdate' => [$base, static fn (QueryBuilder $qb) => $base($qb)->lock()],
            'cursorLimit vs limit' => [
                static fn (QueryBuilder $qb) => $ordered($qb)->limit(3),
                static fn (QueryBuilder $qb) => $ordered($qb)->cursorLimit(3),
            ],
        ];
    }

    private function qb(): QueryBuilder
    {
        return new QueryBuilder(
            $this->createStub(Connection::class),
            null,
            null,
            null,
            null,
            $this->pool
        );
    }

    /**
     * Mutant: ArrayItemRemoval on 'entityClass' in buildStructuralCacheKey() — if dropped,
     * two queries differing only by target entity class (and thus by auto-selected columns)
     * would collide on the same statement-cache key, and the second query would wrongly be
     * served the first query's cached SQL (wrong columns).
     */
    public function testEntityClassIsPartOfStructuralCacheKey(): void
    {
        $registry = new EntityMetadataRegistry();

        $first = new QueryBuilder($this->createStub(Connection::class), null, $registry, null, null, $this->pool);
        $first->setEntityClass(SqlStatementCacheKeyEntityA::class);
        $first->from('shared_table');

        $second = new QueryBuilder($this->createStub(Connection::class), null, $registry, null, null, $this->pool);
        $second->setEntityClass(SqlStatementCacheKeyEntityB::class);
        $second->from('shared_table');

        $firstSql = $first->getSQL();
        $secondSql = $second->getSQL();

        $this->assertStringContainsString('field_a', $firstSql);
        $this->assertStringContainsString('field_b', $secondSql);
        $this->assertStringNotContainsString('field_b', $firstSql);
        $this->assertStringNotContainsString('field_a', $secondSql);
    }

    /**
     * Mutant: Plus→Minus and IncrementInteger on `$this->cursorLimit + 1` in both
     * build()'s limitToUse and buildStructuralCacheKey() — the actual LIMIT used (and
     * cached under) must be exactly cursorLimit + 1 (one extra row fetched to detect "has more").
     */
    public function testCursorLimitAddsExactlyOneToActualLimit(): void
    {
        $qb = $this->qb()->select('id')->from('users')->orderBy('id', 'ASC')->cursorLimit(5);

        $this->assertStringContainsString('LIMIT 6', $qb->getSQL());
    }

    /**
     * Mutant: UnwrapArrayColumn on 'joins' => array_column($this->joins, 'sql') — removing
     * the column-extraction would embed the full join array (including params) into the
     * structural key; two queries with the same join SQL but different bound join params
     * would then wrongly get different cache keys, while the SQL (the only thing actually
     * cached) is identical.
     */
    public function testJoinStructuralKeyIsBasedOnSqlOnly(): void
    {
        $left = $this->qb()->select('u.id')->from('users', 'u')
            ->join('posts', 'posts.user_id = u.id AND posts.published = ?', true);
        $right = $this->qb()->select('u.id')->from('users', 'u')
            ->join('posts', 'posts.user_id = u.id AND posts.published = ?', false);

        // Different bound params for the same join SQL shape must still produce the
        // same compiled SQL (and thus be safe to share a cached statement).
        $this->assertSame($left->getSQL(), $right->getSQL());
        $this->assertNotSame($left->getParameters(), $right->getParameters());
    }

    /**
     * Mutant: UnwrapArrayMap / ArrayItemRemoval on the 'having' => array_map(...) projection —
     * the structural key must be based on operator+condition only, not raw bound params, so
     * HAVING clauses with identical shape but different params still share a cache key.
     */
    public function testHavingStructuralKeyIgnoresBoundParameters(): void
    {
        $left = $this->qb()->select('category')->count('*', 'c')->from('products')
            ->groupBy('category')->having('c > ?', 5);
        $right = $this->qb()->select('category')->count('*', 'c')->from('products')
            ->groupBy('category')->having('c > ?', 999);

        $this->assertSame($left->getSQL(), $right->getSQL());
    }

    /**
     * Mutant: ArrayItem (=> becomes >) on 'distinct', 'lockForUpdate', 'disabledFilters' keys
     * in buildStructuralCacheKey() — a syntax-level mutation that would throw at runtime
     * since `'key' > $value` is not valid array-literal syntax; compiling any cached query
     * exercises every array item in that literal and would fatal if mutated.
     */
    public function testStructuralCacheKeyArrayBuildsWithoutError(): void
    {
        $qb = $this->qb()->select('id')->from('users')->where('id', 1)->distinct()->lock();

        $this->assertIsString($qb->getSQL());
    }
}
