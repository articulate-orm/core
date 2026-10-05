<?php

namespace Articulate\Tests\Modules\Database\SchemaComparator;

use Articulate\Modules\Database\SchemaComparator\Comparators\IndexComparator;
use Articulate\Modules\Database\SchemaComparator\Models\CompareResult;
use PHPUnit\Framework\TestCase;

class IndexComparatorTest extends TestCase {
    private IndexComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new IndexComparator();
    }

    public function testIndexComparatorCanBeInstantiated(): void
    {
        $this->assertInstanceOf(IndexComparator::class, $this->comparator);
    }

    public function testCompareIndexesCreatesNewIndexDefaultingConcurrentToFalse(): void
    {
        $indexInstance = new class {
            public array $columns = ['email'];
            public bool $unique = true;
        };

        $indexesToRemove = [];
        $results = $this->comparator->compareIndexes(
            ['idx_email' => $indexInstance],
            [],
            $indexesToRemove,
            [],
            []
        );

        $this->assertCount(1, $results);
        $this->assertEquals('idx_email', $results[0]->name);
        $this->assertEquals(CompareResult::OPERATION_CREATE, $results[0]->operation);
        $this->assertTrue($results[0]->isUnique);
        $this->assertFalse($results[0]->isConcurrent);
    }

    public function testCompareIndexesUnsetsRemovalFlagForExistingIndex(): void
    {
        $indexInstance = new class {
            public array $columns = ['email'];
            public bool $unique = true;
        };

        $indexesToRemove = ['idx_email' => true];
        $results = $this->comparator->compareIndexes(
            ['idx_email' => $indexInstance],
            ['idx_email' => ['columns' => ['email'], 'unique' => true]],
            $indexesToRemove,
            [],
            []
        );

        $this->assertEmpty($results);
        $this->assertArrayNotHasKey('idx_email', $indexesToRemove);
    }

    public function testCompareIndexesDeletesIndexNotSkippedAndDefaultsUniqueToFalse(): void
    {
        $indexesToRemove = ['idx_legacy' => true];
        $results = $this->comparator->compareIndexes(
            [],
            ['idx_legacy' => ['columns' => ['legacy_col']]],
            $indexesToRemove,
            [],
            []
        );

        $this->assertCount(1, $results);
        $this->assertEquals('idx_legacy', $results[0]->name);
        $this->assertEquals(CompareResult::OPERATION_DELETE, $results[0]->operation);
        $this->assertFalse($results[0]->isUnique);
    }

    public function testCompareIndexesSkipsDeletionOfPrimaryKeyBackedIndex(): void
    {
        $indexesToRemove = ['idx_primary' => true];
        $results = $this->comparator->compareIndexes(
            [],
            ['idx_primary' => ['columns' => ['id'], 'unique' => true]],
            $indexesToRemove,
            ['id'],
            []
        );

        $this->assertEmpty($results);
        $this->assertArrayNotHasKey('idx_primary', $indexesToRemove);
    }

    public function testRemovePrimaryIndexStripsCaseInsensitivePrimaryKey(): void
    {
        $result = $this->comparator->removePrimaryIndex([
            'PRIMARY' => ['columns' => ['id']],
            'idx_name' => ['columns' => ['name']],
        ]);

        $this->assertArrayNotHasKey('PRIMARY', $result);
        $this->assertArrayHasKey('idx_name', $result);
    }

    public function testRemovePrimaryIndexKeepsIndexesWhenNoPrimaryPresent(): void
    {
        $indexes = ['idx_name' => ['columns' => ['name']]];

        $result = $this->comparator->removePrimaryIndex($indexes);

        $this->assertSame($indexes, $result);
    }

    public function testAddPolymorphicIndexAddsIndexWithExpectedNameAndColumns(): void
    {
        $relation = $this->createStub(\Articulate\Attributes\Reflection\ReflectionRelation::class);
        $relation->method('getPropertyName')->willReturn('commentable');
        $relation->method('getMorphTypeColumnName')->willReturn('commentable_type');
        $relation->method('getMorphIdColumnName')->willReturn('commentable_id');

        $entityIndexes = [];
        $this->comparator->addPolymorphicIndex($entityIndexes, $relation);

        $this->assertArrayHasKey('commentable_morph_index', $entityIndexes);
        $this->assertEquals(
            ['commentable_type', 'commentable_id'],
            $entityIndexes['commentable_morph_index']->columns
        );
        $this->assertFalse($entityIndexes['commentable_morph_index']->unique);
    }

    public function testShouldSkipIndexDeletionReturnsFalseWhenColumnsEmpty(): void
    {
        $result = $this->comparator->shouldSkipIndexDeletion('idx_empty', ['columns' => []], ['id'], []);

        $this->assertFalse($result);
    }

    public function testShouldSkipIndexDeletionReturnsFalseWhenColumnsMissingEntirely(): void
    {
        $result = $this->comparator->shouldSkipIndexDeletion('idx_missing', [], ['id'], []);

        $this->assertFalse($result);
    }

    public function testShouldSkipIndexDeletionReturnsTrueWhenColumnsMatchPrimaryKeyCaseInsensitively(): void
    {
        $result = $this->comparator->shouldSkipIndexDeletion(
            'idx_pk',
            ['columns' => ['ID']],
            ['id'],
            []
        );

        $this->assertTrue($result);
    }

    public function testShouldSkipIndexDeletionReturnsFalseWhenColumnsDoNotMatchPrimaryKey(): void
    {
        $result = $this->comparator->shouldSkipIndexDeletion(
            'idx_other',
            ['columns' => ['other_column']],
            ['id'],
            []
        );

        $this->assertFalse($result);
    }

    public function testShouldSkipIndexDeletionReturnsTrueWhenSingleColumnBacksForeignKeyCaseInsensitively(): void
    {
        $result = $this->comparator->shouldSkipIndexDeletion(
            'idx_fk',
            ['columns' => ['USER_ID']],
            [],
            ['fk_users' => ['column' => 'user_id']]
        );

        $this->assertTrue($result);
    }

    public function testShouldSkipIndexDeletionReturnsFalseWhenMultipleColumnsEvenIfFirstMatchesForeignKey(): void
    {
        $result = $this->comparator->shouldSkipIndexDeletion(
            'idx_composite',
            ['columns' => ['user_id', 'other']],
            [],
            ['fk_users' => ['column' => 'user_id']]
        );

        $this->assertFalse($result);
    }
}
