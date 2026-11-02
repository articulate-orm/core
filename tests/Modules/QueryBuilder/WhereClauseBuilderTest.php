<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Connection;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Modules\QueryBuilder\SqlCompiler;
use Articulate\Modules\QueryBuilder\WhereClauseBuilder;
use Articulate\Tests\DatabaseTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Direct unit coverage of WhereClauseBuilder's internal condition-array shape, which is
 * not always distinguishable by inspecting only the compiled SQL string produced through
 * QueryBuilder's fluent API (e.g. the 'operator' key feeding buildWhereClause's grouping
 * logic, or the exact boolean stored under 'raw').
 */
class WhereClauseBuilderTest extends DatabaseTestCase {
    private Connection $connection;

    private WhereClauseBuilder $builder;

    private function makeBuilder(): WhereClauseBuilder
    {
        $sqlCompiler = new SqlCompiler();

        return new WhereClauseBuilder(
            fn () => new QueryBuilder($this->connection, null, null, null, null, null, null),
            $sqlCompiler
        );
    }

    /**
     * Mutant: ArrayItemRemoval on 'operator' => 'AND' in whereIn()'s QueryBuilder-subquery
     * branch (WhereClauseBuilder.php:105..110). Without the 'operator' key, buildWhereClause()
     * reads $condition['operator'] on an undefined index when a second condition follows,
     * breaking AND-combination between a subquery-based whereIn() and anything after it.
     */
    #[DataProvider('databaseProvider')]
    public function testWhereInWithSubQueryUsesAndOperatorByDefault(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->builder = $this->makeBuilder();

        $subQuery = new QueryBuilder($this->connection);
        $subQuery->select('id')->from('allowed_ids');

        $this->builder->whereIn('id', $subQuery);
        $this->builder->addCondition('active = ?', [true], 'AND');

        $conditions = $this->builder->getConditions();

        $this->assertSame('AND', $conditions[0]['operator']);
        $this->assertSame('AND', $conditions[1]['operator']);
    }

    /**
     * Mutant: ArrayItemRemoval on 'operator' => 'AND' in whereNot()'s group-condition branch
     * (WhereClauseBuilder.php:205..210). The generated "NOT (...)" condition must be combinable
     * with a following AND condition — missing 'operator' breaks buildWhereClause() when more
     * than one top-level condition exists.
     */
    #[DataProvider('databaseProvider')]
    public function testWhereNotGroupUsesAndOperatorByDefault(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->builder = $this->makeBuilder();

        $this->builder->whereNot(function (QueryBuilder $q) {
            $q->where('status = ?', 'blocked');
        });
        $this->builder->addCondition('active = ?', [true], 'AND');

        $conditions = $this->builder->getConditions();

        $this->assertSame('AND', $conditions[0]['operator']);
        $this->assertSame('NOT (status = ?)', $conditions[0]['condition']);
    }

    /**
     * Mutant: UnwrapArrayValues on `array_values($values)[0]` → `$values[0]` in whereIn()
     * (WhereClauseBuilder.php:121). A non-sequential single-element array (e.g. from a
     * filtered/keyed array) has no index 0, so `$values[0]` would emit an undefined-index
     * warning/null, while `array_values($values)[0]` correctly re-indexes first.
     */
    #[DataProvider('databaseProvider')]
    public function testWhereInWithSingleNonSequentialKeyExtractsValueCorrectly(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->builder = $this->makeBuilder();

        // Non-sequential key: simulates array_filter()'d input where the surviving
        // element keeps its original (non-zero) key.
        $this->builder->whereIn('id', [3 => 42]);

        $conditions = $this->builder->getConditions();

        $this->assertSame('id = ?', $conditions[0]['condition']);
        $this->assertSame([42], $conditions[0]['params']);
    }

    /**
     * Mutant: PublicVisibility on orWhereNull() (WhereClauseBuilder.php:161) — demoting it to
     * protected would break orWhere($column, null), which calls it directly as an external
     * caller through QueryBuilder's public API.
     */
    #[DataProvider('databaseProvider')]
    public function testOrWhereWithNullValueDelegatesToPublicOrWhereNull(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->builder = $this->makeBuilder();

        $this->builder->where('status = ?', 'active');
        $result = $this->builder->orWhere('deleted_at', null);

        $this->assertSame($this->builder, $result);
        $conditions = $this->builder->getConditions();
        $this->assertSame('OR', $conditions[1]['operator']);
        $this->assertSame('deleted_at IS NULL', $conditions[1]['condition']);
    }

    /**
     * Mutant: TrueValue on 'raw' => true → false in whereRaw() (WhereClauseBuilder.php:237).
     * SqlCompiler::collectParameters()/buildSelectClause() gate raw-expression param
     * collection on `$selectItem['raw'] === truthy`; the same convention is relied on for
     * whereRaw's params to be trusted verbatim rather than placeholder-expanded again.
     */
    #[DataProvider('databaseProvider')]
    public function testWhereRawMarksConditionAsRaw(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->builder = $this->makeBuilder();

        $this->builder->whereRaw('JSON_CONTAINS(tags, ?)', 'urgent');

        $conditions = $this->builder->getConditions();

        $this->assertArrayHasKey('raw', $conditions[0]);
        $this->assertTrue($conditions[0]['raw']);
    }

    /**
     * Mutant: ReturnRemoval on `return [$operatorOrValue];` inside extractRawParams()'s
     * array-but-not-BETWEEN branch (WhereClauseBuilder.php:308). Without the return, PHP falls
     * through to the final `return [$operatorOrValue];` anyway for a top-level array argument —
     * but for a raw condition carrying an inner array value (e.g. JSON column match), the two
     * branches must both wrap it in a single-element array, not flatten it.
     */
    #[DataProvider('databaseProvider')]
    public function testRawConditionWithArrayValueWrapsAsSingleParam(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->builder = $this->makeBuilder();

        $this->builder->where('tags IN (?)', ['urgent', 'escalated']);

        $conditions = $this->builder->getConditions();

        $this->assertSame([['urgent', 'escalated']], $conditions[0]['params']);
    }

    /**
     * Mutant: default throw branch in buildConditionFromOperator()'s match expression.
     * Covers that an unsupported operator always throws, exercising the match's default arm
     * so it can't be silently removed/short-circuited.
     */
    #[DataProvider('databaseProvider')]
    public function testUnsupportedOperatorThrows(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->connection = $this->getCurrentConnection();
        $this->builder = $this->makeBuilder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported operator: regexp');

        $this->builder->where('name', 'regexp', '^a');
    }
}
