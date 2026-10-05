<?php

namespace Articulate\Tests\Modules\DatabaseSchemaReader;

use Articulate\Modules\Database\SchemaReader\DatabaseColumn;
use Articulate\Tests\AbstractTestCase;

class DatabaseColumnTest extends AbstractTestCase {
    public function testStringTypeWithLength(): void
    {
        $column = new DatabaseColumn('name', 'VARCHAR(255)', false, null);

        $this->assertEquals('name', $column->name);
        $this->assertEquals('VARCHAR', $column->type);
        $this->assertEquals('string', $column->phpType);
        $this->assertEquals(255, $column->length);
        $this->assertFalse($column->isNullable);
        $this->assertNull($column->defaultValue);
    }

    public function testBoolTypeFromTinyInt1(): void
    {
        $column = new DatabaseColumn('is_active', 'TINYINT(1)', false, '0');

        $this->assertEquals('is_active', $column->name);
        $this->assertEquals('TINYINT(1)', $column->type);
        $this->assertEquals('bool', $column->phpType);
        $this->assertEquals(1, $column->length);
        $this->assertFalse($column->isNullable);
        $this->assertEquals('0', $column->defaultValue);
    }

    public function testIntTypeFromTinyInt(): void
    {
        $column = new DatabaseColumn('counter', 'TINYINT(2)', false, null);

        $this->assertEquals('counter', $column->name);
        $this->assertEquals('TINYINT', $column->type);
        $this->assertEquals('int', $column->phpType);
        $this->assertEquals(2, $column->length);
        $this->assertFalse($column->isNullable);
    }

    public function testSimpleTypeWithoutLength(): void
    {
        $column = new DatabaseColumn('id', 'INT', false, null);

        $this->assertEquals('id', $column->name);
        $this->assertEquals('INT', $column->type);
        $this->assertEquals('int', $column->phpType);
        $this->assertNull($column->length);
        $this->assertFalse($column->isNullable);
    }

    public function testNullableColumn(): void
    {
        $column = new DatabaseColumn('description', 'TEXT', true, null);

        $this->assertEquals('description', $column->name);
        $this->assertEquals('TEXT', $column->type);
        $this->assertEquals('mixed', $column->phpType);
        $this->assertNull($column->length);
        $this->assertTrue($column->isNullable);
    }

    public function testCommaParameterizedTypeUsesOnlyFirstPartAsLength(): void
    {
        // Covers NotIdentical mutant on strpos($params, ',') !== false -> === false,
        // and the UnwrapTrim/CastInt mutants on (int) trim($paramParts[0]).
        $column = new DatabaseColumn('price', 'NUMERIC(10,2)', false, null);

        $this->assertEquals('NUMERIC', $column->type);
        $this->assertEquals(10, $column->length);
        $this->assertIsInt($column->length);
    }

    public function testCommaParameterizedTypeTrimsWhitespaceAroundLength(): void
    {
        $column = new DatabaseColumn('price', 'DECIMAL( 10 ,2)', false, null);

        $this->assertEquals(10, $column->length);
    }

    public function testParameterizedTypeRequiresClosingParenAtEndOfString(): void
    {
        // Covers PregMatchRemoveDollar: without the trailing $ anchor, a type string
        // with trailing content after the closing paren would incorrectly be treated
        // as parameterized. "VARCHAR(255) extra" must fall through to the plain-type
        // branch (length null, type kept verbatim) because of the anchor.
        $column = new DatabaseColumn('weird', 'VARCHAR(255) extra', false, null);

        $this->assertNull($column->length);
        $this->assertEquals('VARCHAR(255) extra', $column->type);
    }
}
