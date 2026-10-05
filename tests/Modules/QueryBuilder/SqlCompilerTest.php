<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Connection;
use Articulate\Modules\QueryBuilder\Cursor;
use Articulate\Modules\QueryBuilder\CursorDirection;
use Articulate\Modules\QueryBuilder\SqlCompiler;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SqlCompilerTest extends DatabaseTestCase {
    private SqlCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new SqlCompiler();
    }

    /**
     * Mutant: Break_ → continue in buildWhereClause()'s mixed-operator detection loop
     * (SqlCompiler.php:254). With 3+ conditions where only the last pair differs, `continue`
     * would keep scanning and never actually matters here because the loop ends anyway —
     * but with a 4th matching condition after the mismatch, `continue` masks the mismatch
     * since $hasMixedOperators would still end up set by the earlier break-triggering
     * iteration. The meaningful distinction is observable via the *parenthesized* grouping
     * buildWhereClause() emits once mixed operators are detected at all.
     */
    #[DataProvider('databaseProvider')]
    public function testMixedOperatorsAreDetectedAndGroupedWithParentheses(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);

        $conditions = [
            ['operator' => 'AND', 'condition' => 'a = ?', 'params' => [1], 'group' => null],
            ['operator' => 'AND', 'condition' => 'b = ?', 'params' => [2], 'group' => null],
            ['operator' => 'OR', 'condition' => 'c = ?', 'params' => [3], 'group' => null],
        ];

        $clause = $this->compiler->buildWhereClause($conditions);

        $this->assertSame('((a = ? AND b = ?) OR c = ?)', $clause);
    }

    /**
     * Mutant: Break_ → continue in buildHavingClause()'s mixed-operator detection loop
     * (SqlCompiler.php:309) — same shape as the WHERE-clause mutant above, but for HAVING.
     */
    #[DataProvider('databaseProvider')]
    public function testMixedHavingOperatorsAreGroupedWithParentheses(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);

        $having = [
            ['operator' => 'AND', 'condition' => 'total > ?', 'params' => [5]],
            ['operator' => 'AND', 'condition' => 'count > ?', 'params' => [1]],
            ['operator' => 'OR', 'condition' => 'avg > ?', 'params' => [10]],
        ];

        [$sql] = $this->compiler->compile(
            null,
            [],
            'products',
            [],
            [],
            [],
            ['category'],
            $having,
            [],
            null,
            null,
            false,
            false
        );

        $this->assertStringContainsString('HAVING ((total > ? AND count > ?) OR avg > ?)', $sql);
    }

    /**
     * Mutant: UnwrapArrayMerge on `$params = array_merge($params, $selectItem['params'])` →
     * `$params = $selectItem['params']` in collectParameters() (SqlCompiler.php:336). Dropping
     * the merge discards parameters accumulated from earlier raw select items whenever a later
     * raw select item also carries params.
     */
    #[DataProvider('databaseProvider')]
    public function testCollectParametersAccumulatesAcrossMultipleRawSelectItems(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);

        $select = [
            ['expression' => 'CASE WHEN a = ? THEN 1 ELSE 0 END', 'raw' => true, 'params' => ['first']],
            ['expression' => 'CASE WHEN b = ? THEN 1 ELSE 0 END', 'raw' => true, 'params' => ['second']],
        ];

        $params = $this->compiler->collectParametersPublic($select, [], [], []);

        $this->assertSame(['first', 'second'], $params);
    }

    /**
     * Mutant: LogicalNot removal on `!empty($orderBy)` → `empty($orderBy)` in
     * buildWhereWithCursor() (SqlCompiler.php:382). A cursor with no ORDER BY columns must
     * never contribute a cursor condition — flipping the condition would instead only
     * attach the cursor condition when ORDER BY is EMPTY, exactly backwards.
     */
    #[DataProvider('databaseProvider')]
    public function testBuildWhereWithCursorAddsNoConditionWithoutOrderBy(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);

        $cursor = new Cursor(['id' => 5], CursorDirection::NEXT);

        $result = $this->compiler->buildWhereWithCursor([], [], $cursor);

        $this->assertSame([], $result);
    }

    /**
     * Mutant: LogicalAnd → LogicalOr on `$cursor !== null && !empty($orderBy)` in
     * buildWhereWithCursor() (SqlCompiler.php:382). With OR, a non-null cursor alone (no
     * ORDER BY) would try to build a cursor condition and either crash or silently no-op in
     * buildCursorCondition's own empty($orderBy) guard — but critically, with OR, an empty
     * cursor-less call with a non-empty orderBy would also wrongly attempt cursor building.
     * This test pins the AND requirement: orderBy alone (no cursor) adds nothing.
     */
    #[DataProvider('databaseProvider')]
    public function testBuildWhereWithCursorAddsNoConditionWithoutCursor(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);

        $result = $this->compiler->buildWhereWithCursor([], ['id ASC'], null);

        $this->assertSame([], $result);
    }

    /**
     * Mutant: LogicalAnd/LogicalNot combined positive case — with both cursor and orderBy
     * present, a cursor condition must actually be appended.
     */
    #[DataProvider('databaseProvider')]
    public function testBuildWhereWithCursorAddsConditionWhenBothPresent(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);

        $cursor = new Cursor([5], CursorDirection::NEXT);

        $result = $this->compiler->buildWhereWithCursor([], ['id ASC'], $cursor);

        $this->assertCount(1, $result);
        $this->assertSame('AND', $result[0]['operator']);
        $this->assertSame('id > ?', $result[0]['condition']);
        $this->assertSame([5], $result[0]['params']);
    }

    /**
     * Mutant: ArrayOneItem on `return $whereWithCursor;` →
     * `count($whereWithCursor) > 1 ? array_slice($whereWithCursor, 0, 1, true) : $whereWithCursor;`
     * in buildWhereWithCursor() (SqlCompiler.php:394). When multiple WHERE conditions plus the
     * cursor condition are combined, all of them must survive — truncating to one entry would
     * silently drop user-supplied WHERE conditions whenever a cursor is also active.
     */
    #[DataProvider('databaseProvider')]
    public function testBuildWhereWithCursorPreservesAllExistingConditions(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);

        $cursor = new Cursor([5], CursorDirection::NEXT);
        $existing = [
            ['operator' => 'AND', 'condition' => 'active = ?', 'params' => [true], 'group' => null],
            ['operator' => 'AND', 'condition' => 'role = ?', 'params' => ['admin'], 'group' => null],
        ];

        $result = $this->compiler->buildWhereWithCursor($existing, ['id ASC'], $cursor);

        $this->assertCount(3, $result);
        $this->assertSame('active = ?', $result[0]['condition']);
        $this->assertSame('role = ?', $result[1]['condition']);
        $this->assertSame('id > ?', $result[2]['condition']);
    }
}
