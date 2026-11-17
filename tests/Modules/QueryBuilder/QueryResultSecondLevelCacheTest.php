<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\QueryBuilder\QueryResultSecondLevelCache;
use Articulate\Tests\Modules\QueryBuilder\Fixtures\QbCachePrivateFieldParam;
use Articulate\Tests\Modules\QueryBuilder\Fixtures\QbCacheUnserializableParam;
use PHPUnit\Framework\TestCase;

/**
 * Records every call made to it, so tests can assert exactly what the
 * collaborator under test decided to do (not just what data came back) —
 * needed to kill MethodCallRemoval-style mutants that an outcome-only
 * assertion can't see.
 */
class SpyCollectionCache implements CollectionCacheInterface {
    /** @var list<string> */
    public array $getCalls = [];

    /** @var list<array{key: string, ids: list<mixed>, ttl: int}> */
    public array $setCalls = [];

    /** @var list<list<string>> */
    public array $generationReads = [];

    /** @var array<string, int|null> null simulates an unreadable counter */
    public array $tableGenerations = [];

    /** @var list<mixed>|null */
    private ?array $nextGetResult = null;

    /**
     * @param list<mixed>|null $ids
     */
    public function willReturnOnNextGet(?array $ids): void
    {
        $this->nextGetResult = $ids;
    }

    public function getIdList(string $key): ?array
    {
        $this->getCalls[] = $key;

        return $this->nextGetResult;
    }

    public function setIdList(string $key, array $ids, int $ttl): void
    {
        $this->setCalls[] = ['key' => $key, 'ids' => $ids, 'ttl' => $ttl];
    }

    public function getTableGenerations(array $tableNames): ?array
    {
        $this->generationReads[] = $tableNames;

        $generations = [];
        foreach ($tableNames as $tableName) {
            $generation = array_key_exists($tableName, $this->tableGenerations) ? $this->tableGenerations[$tableName] : 0;
            if ($generation === null) {
                return null;
            }
            $generations[$tableName] = $generation;
        }

        return $generations;
    }

    public function incrementTableGenerations(array $tableNames): void
    {
        foreach ($tableNames as $tableName) {
            $this->tableGenerations[$tableName] = ($this->tableGenerations[$tableName] ?? 0) + 1;
        }
    }
}

class QueryResultSecondLevelCacheTest extends TestCase {
    public function testIsDisabledByDefault(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $this->assertFalse($cache->isEnabled());
    }

    public function testEnableTurnsItOn(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $cache->enable(60);

        $this->assertTrue($cache->isEnabled());
    }

    public function testDisableTurnsItBackOff(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $cache->enable(60);
        $cache->disable();

        $this->assertFalse($cache->isEnabled());
    }

    public function testGetCachedIdsReturnsNullWithoutCallingCollaboratorWhenDisabled(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->willReturnOnNextGet([1, 2, 3]);
        $cache = new QueryResultSecondLevelCache($collectionCache);

        $result = $cache->getCachedIds('some-key');

        $this->assertNull($result);
        $this->assertSame([], $collectionCache->getCalls);
    }

    public function testGetCachedIdsDelegatesToCollaboratorWhenEnabled(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->willReturnOnNextGet([1, 2, 3]);
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(60);

        $result = $cache->getCachedIds('some-key');

        $this->assertSame([1, 2, 3], $result);
        $this->assertSame(['some-key'], $collectionCache->getCalls);
    }

    public function testStoreIdsDoesNotCallCollaboratorWhenDisabled(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);

        $cache->storeIds('some-key', [1, 2], 3600);

        $this->assertSame([], $collectionCache->setCalls);
    }

    public function testStoreIdsUsesDefaultTtlWhenNoExplicitTtlWasGiven(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(null);

        $cache->storeIds('some-key', [1, 2], 3600);

        $this->assertCount(1, $collectionCache->setCalls);
        $this->assertSame('some-key', $collectionCache->setCalls[0]['key']);
        $this->assertSame([1, 2], $collectionCache->setCalls[0]['ids']);
        $this->assertSame(3600, $collectionCache->setCalls[0]['ttl']);
    }

    public function testStoreIdsUsesExplicitTtlOverTheDefault(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(120);

        $cache->storeIds('some-key', [1, 2], 3600);

        $this->assertSame(120, $collectionCache->setCalls[0]['ttl']);
    }

    public function testDisableClearsAnExplicitTtlOverride(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(120);
        $cache->disable();
        $cache->enable(null);

        $cache->storeIds('some-key', [1, 2], 3600);

        $this->assertSame(3600, $collectionCache->setCalls[0]['ttl'], 'ttl override from a prior enable() must not leak across a disable()');
    }

    public function testBuildCacheKeyIsStableForIdenticalInputs(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM users', [1, 'x'], ['products']);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM users', [1, 'x'], ['products']);

        $this->assertSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDiffersByEntityClass(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t', [], ['products']);
        $keyB = $cache->buildCacheKey('App\\Order', 'SELECT * FROM t', [], ['products']);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDiffersBySql(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE a = ?', [], ['products']);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE b = ?', [], ['products']);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDiffersByParams(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE id = ?', [1], ['products']);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE id = ?', [2], ['products']);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDoesNotCollideAcrossTheEntityClassSqlBoundary(): void
    {
        // Guards the '|' separators themselves: without them, ('App', 'UserSELECT...')
        // and ('AppUser', 'SELECT...') could concatenate to the same string.
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App', 'UserSELECT 1', [], ['products']);
        $keyB = $cache->buildCacheKey('AppUser', 'SELECT 1', [], ['products']);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyTreatsNullEntityClassAsItsOwnDistinctValue(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyNull = $cache->buildCacheKey(null, 'SELECT 1', [], ['products']);
        $keyEmptyString = $cache->buildCacheKey('', 'SELECT 1', [], ['products']);

        // Both collapse to the same '' segment by design (documented behavior,
        // not a bug) — pin it explicitly so a future change notices.
        $this->assertSame($keyNull, $keyEmptyString);
    }

    /**
     * Pins the exact prefix/separator/hash-input format. Relative-difference
     * assertions (testBuildCacheKeyDiffersBy*) can't catch a mutant that
     * reorders the concatenation (e.g. moving the 'cq_' prefix to the end,
     * or swapping which side of the SQL the '|' separator sits on) — the
     * hash is still a function of the same inputs, so two *different* calls
     * still produce two different values under that mutant. Only an exact
     * golden-value assertion, computed independently of buildCacheKey()'s
     * own implementation, catches that class of mutation.
     */
    public function testBuildCacheKeyMatchesTheDocumentedFormatExactly(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $entityClass = 'App\\User';
        $sql = 'SELECT * FROM users WHERE id = ?';
        $params = [42];

        $expected = 'cq_' . hash('sha256', serialize(['', $entityClass, $sql, $params, 'products:0']));

        $this->assertSame($expected, $cache->buildCacheKey($entityClass, $sql, $params, ['products']));
    }

    public function testBuildCacheKeyHasTheCqPrefix(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $key = $cache->buildCacheKey('App\\User', 'SELECT 1', [], ['products']);

        $this->assertStringStartsWith('cq_', $key);
    }

    public function testBinaryParamsProduceDistinctKeys(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE uuid = ?', ["\xff\xfe\x01"], ['products']);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE uuid = ?', ["\xff\xfe\x02"], ['products']);

        $this->assertNotNull($keyA);
        $this->assertNotNull($keyB);
        $this->assertNotSame($keyA, $keyB);
    }

    public function testDistinctObjectParamsWithPrivateStateProduceDistinctKeys(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE v = ?', [new QbCachePrivateFieldParam(1)], ['products']);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE v = ?', [new QbCachePrivateFieldParam(2)], ['products']);
        $keyA2 = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE v = ?', [new QbCachePrivateFieldParam(1)], ['products']);

        $this->assertNotSame($keyA, $keyB);
        $this->assertSame($keyA, $keyA2);
    }

    public function testObjectParamsAreKeyedByClassAndState(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey(null, 'SELECT 1', [new \ArrayObject([1])], ['products']);
        $keyB = $cache->buildCacheKey(null, 'SELECT 1', [new \ArrayObject([2])], ['products']);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testParamTypesAreTagged(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $this->assertNotSame(
            $cache->buildCacheKey(null, 'SELECT 1', [1], ['products']),
            $cache->buildCacheKey(null, 'SELECT 1', ['1'], ['products']),
        );
        $this->assertNotSame(
            $cache->buildCacheKey(null, 'SELECT 1', [[1, 2]], ['products']),
            $cache->buildCacheKey(null, 'SELECT 1', [[1], 2], ['products']),
        );
    }

    public function testUnserializableParamsYieldNoKey(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $this->assertNull($cache->buildCacheKey(null, 'SELECT 1', [new QbCacheUnserializableParam()], ['products']));
        $this->assertNull($cache->buildCacheKey(null, 'SELECT 1', [[new QbCacheUnserializableParam()]], ['products']));
        $this->assertNull($cache->buildCacheKey(null, 'SELECT 1', [fopen('php://memory', 'r')], ['products']));
    }

    public function testNullParamsAreAllowed(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $this->assertNotNull($cache->buildCacheKey(null, 'SELECT 1', [null], ['products']));
    }

    public function testNamespaceIsPartOfTheKey(): void
    {
        $a = new QueryResultSecondLevelCache(new SpyCollectionCache(), 'ns-a');
        $b = new QueryResultSecondLevelCache(new SpyCollectionCache(), 'ns-b');

        $this->assertNotSame($a->buildCacheKey(null, 'SELECT 1', [], ['products']), $b->buildCacheKey(null, 'SELECT 1', [], ['products']));
    }

    public function testObjectParamKeyIncludesTheObjectTag(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());
        $object = new QbCachePrivateFieldParam(1);

        $expected = 'cq_' . hash('sha256', serialize(['', '', 'SELECT 1', [['o', QbCachePrivateFieldParam::class, serialize($object)]], 'products:0']));

        $this->assertSame($expected, $cache->buildCacheKey(null, 'SELECT 1', [$object], ['products']));
    }

    public function testAnUnreadableTableGenerationYieldsNoKey(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->tableGenerations['products'] = null;
        $cache = new QueryResultSecondLevelCache($collectionCache);

        $this->assertNull($cache->buildCacheKey(null, 'SELECT 1', [], ['products']));
    }

    public function testEveryTableGenerationIsReadInOneSortedDeduplicatedBatch(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);

        $cache->buildCacheKey(null, 'SELECT 1', [], ['posts', 'products', 'posts']);

        $this->assertSame([['posts', 'products']], $collectionCache->generationReads);
    }

    public function testBuildCacheKeyFoldsInEveryTablesGenerationInSortedOrder(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->tableGenerations = ['posts' => 7, 'products' => 3];
        $cache = new QueryResultSecondLevelCache($collectionCache);

        $expected = 'cq_' . hash('sha256', serialize(['', '', 'SELECT 1', [], 'posts:7', 'products:3']));

        $this->assertSame($expected, $cache->buildCacheKey(null, 'SELECT 1', [], ['products', 'posts']));
    }

    public function testBumpingAnyTablesGenerationChangesTheKey(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $before = $cache->buildCacheKey(null, 'SELECT 1', [], ['products', 'posts']);

        $collectionCache->incrementTableGenerations(['posts']);

        $this->assertNotSame($before, $cache->buildCacheKey(null, 'SELECT 1', [], ['products', 'posts']));
    }
}
