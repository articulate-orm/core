<?php

namespace Articulate\Modules\QueryBuilder;

use Articulate\Schema\EntityMetadataRegistry;
use InvalidArgumentException;

class SqlCompiler {
    private ?EntityMetadataRegistry $metadataRegistry;

    private ?string $entityClass = null;

    public function __construct(?EntityMetadataRegistry $metadataRegistry = null)
    {
        $this->metadataRegistry = $metadataRegistry;
    }

    public function setEntityClass(?string $entityClass): void
    {
        $this->entityClass = $entityClass;
    }

    public function compile(
        ?string $rawSql,
        array $rawParams,
        string $from,
        array $select,
        array $joins,
        array $where,
        array $groupBy,
        array $having,
        array $orderBy,
        ?int $limit,
        ?int $offset,
        bool $distinct,
        bool $lockForUpdate,
        ?Cursor $cursor = null
    ): array {
        if ($rawSql !== null) {
            return [$rawSql, $rawParams];
        }

        $selectClause = $distinct ? 'SELECT DISTINCT ' : 'SELECT ';
        $sql = $selectClause . $this->buildSelectClause($select, $joins === [] ? null : $from);
        $sql .= ' FROM ' . $from;

        if (!empty($joins)) {
            $joinSqls = array_column($joins, 'sql');
            $sql .= ' ' . implode(' ', $joinSqls);
        }

        $whereWithCursor = $where;
        if ($cursor !== null && !empty($orderBy)) {
            $cursorCondition = $this->buildCursorCondition($orderBy, $cursor);
            if ($cursorCondition !== null) {
                $whereWithCursor[] = [
                    'operator' => 'AND',
                    'condition' => $cursorCondition['condition'],
                    'params' => $cursorCondition['params'],
                    'group' => null,
                ];
            }
        }

        if (!empty($whereWithCursor)) {
            $whereClause = $this->buildWhereClause($whereWithCursor);
            $sql .= ' WHERE ' . $whereClause;
        }

        if (!empty($groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $groupBy);
        }

        if (!empty($having)) {
            $havingClause = $this->buildHavingClause($having);
            $sql .= ' HAVING ' . $havingClause;
        }

        if (!empty($orderBy)) {
            $effectiveOrderBy = $orderBy;
            if ($cursor !== null && $cursor->getDirection() === CursorDirection::PREV) {
                $effectiveOrderBy = $this->reverseOrderBy($orderBy);
            }
            $sql .= ' ORDER BY ' . implode(', ', $effectiveOrderBy);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }

        if ($lockForUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $params = $this->collectParameters($select, $joins, $whereWithCursor, $having);

        return [$sql, $params];
    }

    private function buildCursorCondition(array $orderBy, Cursor $cursor): ?array
    {
        if (empty($orderBy)) {
            return null;
        }

        $parsedOrderBy = $this->parseOrderBy($orderBy);
        $cursorValues = $cursor->getValues();
        $direction = $cursor->getDirection();

        if (count($cursorValues) !== count($parsedOrderBy)) {
            throw new InvalidArgumentException('Cursor values count must match ORDER BY columns count');
        }

        $isPrev = $direction === CursorDirection::PREV;

        $conditions = [];
        $params = [];

        if (count($parsedOrderBy) === 1) {
            $column = $parsedOrderBy[0]['column'];
            $orderDirection = $parsedOrderBy[0]['direction'];
            $value = $cursorValues[0];

            $effectiveDirection = $isPrev ? $this->reverseDirection($orderDirection) : $orderDirection;
            $operator = $this->getCursorOperator($effectiveDirection);
            $conditions[] = "{$column} {$operator} ?";
            $params[] = $value;
        } else {
            $col1 = $parsedOrderBy[0]['column'];
            $dir1 = $parsedOrderBy[0]['direction'];
            $val1 = $cursorValues[0];

            $col2 = $parsedOrderBy[1]['column'];
            $dir2 = $parsedOrderBy[1]['direction'];
            $val2 = $cursorValues[1];

            $effectiveDir1 = $isPrev ? $this->reverseDirection($dir1) : $dir1;
            $effectiveDir2 = $isPrev ? $this->reverseDirection($dir2) : $dir2;

            $op1 = $this->getCursorOperator($effectiveDir1);
            $op2 = $this->getCursorOperator($effectiveDir2);

            $conditions[] = "({$col1} {$op1} ? OR ({$col1} = ? AND {$col2} {$op2} ?))";
            $params[] = $val1;
            $params[] = $val1;
            $params[] = $val2;
        }

        return [
            'condition' => implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    private function parseOrderBy(array $orderBy): array
    {
        $parsed = [];
        foreach ($orderBy as $orderItem) {
            if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', trim($orderItem), $matches)) {
                $parsed[] = [
                    'column' => trim($matches[1]),
                    'direction' => strtoupper($matches[2]),
                ];
            } else {
                $parsed[] = [
                    'column' => trim($orderItem),
                    'direction' => 'ASC',
                ];
            }
        }

        return $parsed;
    }

    private function getCursorOperator(string $orderDirection): string
    {
        return strtoupper($orderDirection) === 'ASC' ? '>' : '<';
    }

    private function reverseDirection(string $direction): string
    {
        return strtoupper($direction) === 'ASC' ? 'DESC' : 'ASC';
    }

    private function reverseOrderBy(array $orderBy): array
    {
        $reversed = [];
        foreach ($orderBy as $orderItem) {
            if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', trim($orderItem), $matches)) {
                $column = trim($matches[1]);
                $direction = strtoupper($matches[2]);
                $reversedDirection = $direction === 'ASC' ? 'DESC' : 'ASC';
                $reversed[] = "{$column} {$reversedDirection}";
            } else {
                $reversed[] = trim($orderItem) . ' DESC';
            }
        }

        return $reversed;
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function qualifyColumns(array $columns, ?string $from): array
    {
        if ($from === null) {
            return $columns;
        }

        $reference = TableReference::alias($from);

        return array_map(static fn (string $column): string => "{$reference}.{$column}", $columns);
    }

    // With joins, an unqualified entity column can be ambiguous (always so in a self-join)
    private function buildSelectClause(array $select, ?string $qualifyWithFrom): string
    {
        if (empty($select)) {
            if ($this->entityClass && $this->metadataRegistry) {
                try {
                    $columns = $this->metadataRegistry
                        ->getMetadata($this->entityClass)
                        ->getColumnNames();
                    if (!empty($columns)) {
                        return implode(', ', $this->qualifyColumns($columns, $qualifyWithFrom));
                    }
                } catch (InvalidArgumentException $e) {
                }
            }

            return '*';
        }

        $selectParts = [];
        foreach ($select as $selectItem) {
            if (is_array($selectItem) && isset($selectItem['raw']) && $selectItem['raw']) {
                $selectParts[] = $selectItem['expression'];
            } else {
                $selectParts[] = $selectItem;
            }
        }

        return implode(', ', $selectParts);
    }

    public function buildWhereClause(array $conditions): string
    {
        $hasMixedOperators = false;
        $firstOperator = null;
        foreach ($conditions as $index => $condition) {
            if ($index === 0) {
                continue;
            }

            if ($firstOperator === null) {
                $firstOperator = $condition['operator'];

                continue;
            }

            if ($condition['operator'] !== $firstOperator) {
                $hasMixedOperators = true;

                break;
            }
        }

        $result = $this->buildWhereConditionClause($conditions[0]);
        for ($index = 1; $index < count($conditions); $index++) {
            $operator = $conditions[$index]['operator'];
            $clause = $this->buildWhereConditionClause($conditions[$index]);

            if ($hasMixedOperators) {
                $result = "({$result} {$operator} {$clause})";

                continue;
            }

            $result .= " {$operator} {$clause}";
        }

        return $result;
    }

    private function buildWhereConditionClause(array $condition): string
    {
        if ($condition['group'] !== null) {
            $groupClause = $this->buildWhereClause($condition['group']);

            // Special case: if group has single NOT condition, don't add extra parens
            if (count($condition['group']) === 1 && str_starts_with($condition['group'][0]['condition'] ?? '', 'NOT (')) {
                return $groupClause;
            }

            return "({$groupClause})";
        }

        return $condition['condition'];
    }

    private function buildHavingClause(array $conditions): string
    {
        $hasMixedOperators = false;
        $firstOperator = null;
        foreach ($conditions as $index => $condition) {
            if ($index === 0) {
                continue;
            }

            if ($firstOperator === null) {
                $firstOperator = $condition['operator'];

                continue;
            }

            if ($condition['operator'] !== $firstOperator) {
                $hasMixedOperators = true;

                break;
            }
        }

        $result = $conditions[0]['condition'];
        for ($index = 1; $index < count($conditions); $index++) {
            $operator = $conditions[$index]['operator'];
            $clause = $conditions[$index]['condition'];

            if ($hasMixedOperators) {
                $result = "({$result} {$operator} {$clause})";

                continue;
            }

            $result .= " {$operator} {$clause}";
        }

        return $result;
    }

    private function collectParameters(array $select, array $joins, array $where, array $having): array
    {
        $params = [];

        foreach ($select as $selectItem) {
            if (is_array($selectItem) && isset($selectItem['raw']) && $selectItem['raw']) {
                $params = array_merge($params, $selectItem['params']);
            }
        }

        foreach ($joins as $join) {
            $params = array_merge($params, $join['params']);
        }

        $params = array_merge($params, $this->collectWhereParameters($where));

        foreach ($having as $havingClause) {
            $params = array_merge($params, $havingClause['params']);
        }

        return $params;
    }

    private function collectWhereParameters(array $conditions): array
    {
        $params = [];

        foreach ($conditions as $condition) {
            if ($condition['group'] !== null) {
                $params = array_merge($params, $this->collectWhereParameters($condition['group']));
            } else {
                $params = array_merge($params, $condition['params']);
            }
        }

        return $params;
    }

    public function collectWhereParametersPublic(array $conditions): array
    {
        return $this->collectWhereParameters($conditions);
    }

    public function collectParametersPublic(array $select, array $joins, array $where, array $having): array
    {
        return $this->collectParameters($select, $joins, $where, $having);
    }

    public function buildWhereWithCursor(array $where, array $orderBy, ?Cursor $cursor): array
    {
        $whereWithCursor = $where;

        if ($cursor !== null && !empty($orderBy)) {
            $cursorCondition = $this->buildCursorCondition($orderBy, $cursor);
            if ($cursorCondition !== null) {
                $whereWithCursor[] = [
                    'operator' => 'AND',
                    'condition' => $cursorCondition['condition'],
                    'params' => $cursorCondition['params'],
                    'group' => null,
                ];
            }
        }

        return $whereWithCursor;
    }

    /**
     * Compile INSERT SQL statement.
     *
     * @param string $table Table name
     * @param array $columns Column names
     * @param array $valuesRows Array of value arrays (for multi-row insert)
     * @param array $returning Columns to return (PostgreSQL)
     * @return array{string, array} [sql, params]
     */
    public function compileInsert(string $table, array $columns, array $valuesRows, array $returning = []): array
    {
        if (empty($columns)) {
            throw new InvalidArgumentException('INSERT requires at least one column');
        }

        if (empty($valuesRows)) {
            throw new InvalidArgumentException('INSERT requires at least one row of values');
        }

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ';

        $params = [];
        $valueParts = [];

        foreach ($valuesRows as $rowValues) {
            if (count($rowValues) !== count($columns)) {
                throw new InvalidArgumentException('Number of values must match number of columns');
            }

            $valueParts[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $params = array_merge($params, $rowValues);
        }

        $sql .= implode(', ', $valueParts);

        if (!empty($returning)) {
            $sql .= ' RETURNING ' . implode(', ', $returning);
        }

        return [$sql, $params];
    }

    /**
     * Compile UPDATE SQL statement.
     *
     * @param string $table Table name
     * @param array $set Array of [column => value] pairs
     * @param array $where Where conditions
     * @param array $returning Columns to return (PostgreSQL)
     * @return array{string, array} [sql, params]
     */
    public function compileUpdate(string $table, array $set, array $where, array $returning = []): array
    {
        if (empty($set)) {
            throw new InvalidArgumentException('UPDATE requires at least one SET clause');
        }

        $sql = 'UPDATE ' . $table . ' SET ';

        $setParts = [];
        $params = [];

        foreach ($set as $column => $value) {
            $setParts[] = $column . ' = ?';
            $params[] = $value;
        }

        $sql .= implode(', ', $setParts);

        if (!empty($where)) {
            $whereClause = $this->buildWhereClause($where);
            $sql .= ' WHERE ' . $whereClause;
            $params = array_merge($params, $this->collectWhereParameters($where));
        }

        if (!empty($returning)) {
            $sql .= ' RETURNING ' . implode(', ', $returning);
        }

        return [$sql, $params];
    }

    /**
     * Compile DELETE SQL statement.
     *
     * @param string $table Table name
     * @param array $where Where conditions
     * @param array $returning Columns to return (PostgreSQL)
     * @return array{string, array} [sql, params]
     */
    public function compileDelete(string $table, array $where, array $returning = []): array
    {
        $sql = 'DELETE FROM ' . $table;

        $params = [];

        if (!empty($where)) {
            $whereClause = $this->buildWhereClause($where);
            $sql .= ' WHERE ' . $whereClause;
            $params = array_merge($params, $this->collectWhereParameters($where));
        }

        if (!empty($returning)) {
            $sql .= ' RETURNING ' . implode(', ', $returning);
        }

        return [$sql, $params];
    }
}
