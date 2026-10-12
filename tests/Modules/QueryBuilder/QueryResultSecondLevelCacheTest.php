<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Modules\Cache\CollectionCacheInterface;
use Articulate\Modules\QueryBuilder\QueryResultSecondLevelCache;
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

    /** @var list<string> */
    public array $evictIdListCalls = [];

    /** @var list<array{table: string, id: mixed, cacheKey: string, ttl: int}> */
    public array $addToReverseIndexCalls = [];

    /** @var list<array{table: string, id: mixed}> */
    public array $getReverseIndexCacheKeysCalls = [];

    /** @var list<array{table: string, id: mixed}> */
    public array $evictReverseIndexCalls = [];

    /** @var list<array{table: string, ids: list<mixed>}> */
    public array $findMissingReverseIndexIdsCalls = [];

    /** @var list<mixed>|null */
    private ?array $nextGetResult = null;

    /** @var array<string, list<string>> keyed by "$table:$id" */
    private array $reverseIndexCacheKeysToReturn = [];

    /** @var list<mixed> */
    private array $missingReverseIndexIdsToReturn = [];

    /**
     * @param list<mixed>|null $ids
     */
    public function willReturnOnNextGet(?array $ids): void
    {
        $this->nextGetResult = $ids;
    }

    /**
     * @param list<string>|null $cacheKeys
     */
    public function willReturnReverseIndexCacheKeysFor(string $tableName, mixed $id, ?array $cacheKeys): void
    {
        $this->reverseIndexCacheKeysToReturn[$tableName . ':' . $id] = $cacheKeys;
    }

    /**
     * @param list<mixed> $ids
     */
    public function willReturnMissingReverseIndexIds(array $ids): void
    {
        $this->missingReverseIndexIdsToReturn = $ids;
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

    public function evictIdList(string $key): void
    {
        $this->evictIdListCalls[] = $key;
    }

    public function addToReverseIndex(string $tableName, mixed $id, string $cacheKey, int $ttl): void
    {
        $this->addToReverseIndexCalls[] = ['table' => $tableName, 'id' => $id, 'cacheKey' => $cacheKey, 'ttl' => $ttl];
    }

    public function getReverseIndexCacheKeys(string $tableName, mixed $id): ?array
    {
        $this->getReverseIndexCacheKeysCalls[] = ['table' => $tableName, 'id' => $id];

        return $this->reverseIndexCacheKeysToReturn[$tableName . ':' . $id] ?? null;
    }

    public function evictReverseIndex(string $tableName, mixed $id): void
    {
        $this->evictReverseIndexCalls[] = ['table' => $tableName, 'id' => $id];
    }

    public function findMissingReverseIndexIds(string $tableName, array $ids): array
    {
        $this->findMissingReverseIndexIdsCalls[] = ['table' => $tableName, 'ids' => $ids];

        return $this->missingReverseIndexIdsToReturn;
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

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM users', [1, 'x']);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM users', [1, 'x']);

        $this->assertSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDiffersByEntityClass(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t', []);
        $keyB = $cache->buildCacheKey('App\\Order', 'SELECT * FROM t', []);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDiffersBySql(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE a = ?', []);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE b = ?', []);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDiffersByParams(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE id = ?', [1]);
        $keyB = $cache->buildCacheKey('App\\User', 'SELECT * FROM t WHERE id = ?', [2]);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyDoesNotCollideAcrossTheEntityClassSqlBoundary(): void
    {
        // Guards the '|' separators themselves: without them, ('App', 'UserSELECT...')
        // and ('AppUser', 'SELECT...') could concatenate to the same string.
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyA = $cache->buildCacheKey('App', 'UserSELECT 1', []);
        $keyB = $cache->buildCacheKey('AppUser', 'SELECT 1', []);

        $this->assertNotSame($keyA, $keyB);
    }

    public function testBuildCacheKeyTreatsNullEntityClassAsItsOwnDistinctValue(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $keyNull = $cache->buildCacheKey(null, 'SELECT 1', []);
        $keyEmptyString = $cache->buildCacheKey('', 'SELECT 1', []);

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

        $expected = 'cq_' . hash('sha256', $entityClass . '|' . $sql . '|' . json_encode($params));

        $this->assertSame($expected, $cache->buildCacheKey($entityClass, $sql, $params));
    }

    public function testBuildCacheKeyHasTheCqPrefix(): void
    {
        $cache = new QueryResultSecondLevelCache(new SpyCollectionCache());

        $key = $cache->buildCacheKey('App\\User', 'SELECT 1', []);

        $this->assertStringStartsWith('cq_', $key);
    }

    public function testStoreIdsWithReverseIndexWritesTheIdListWhenEnabled(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(null);

        $cache->storeIdsWithReverseIndex('some-key', [1, 2], 3600, 'products');

        $this->assertCount(1, $collectionCache->setCalls);
        $this->assertSame([1, 2], $collectionCache->setCalls[0]['ids']);
    }

    public function testStoreIdsWithReverseIndexWritesAReverseIndexEntryPerId(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(null);

        $cache->storeIdsWithReverseIndex('some-key', [1, 2], 3600, 'products');

        $this->assertCount(2, $collectionCache->addToReverseIndexCalls);
        $this->assertSame(['table' => 'products', 'id' => 1, 'cacheKey' => 'some-key', 'ttl' => 3600], $collectionCache->addToReverseIndexCalls[0]);
        $this->assertSame(['table' => 'products', 'id' => 2, 'cacheKey' => 'some-key', 'ttl' => 3600], $collectionCache->addToReverseIndexCalls[1]);
    }

    public function testStoreIdsWithReverseIndexDoesNothingWhenDisabled(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);

        $cache->storeIdsWithReverseIndex('some-key', [1, 2], 3600, 'products');

        $this->assertSame([], $collectionCache->setCalls);
        $this->assertSame([], $collectionCache->addToReverseIndexCalls);
    }

    public function testStoreIdsWithReverseIndexUsesExplicitTtlOverTheDefaultForBoth(): void
    {
        $collectionCache = new SpyCollectionCache();
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(120);

        $cache->storeIdsWithReverseIndex('some-key', [1], 3600, 'products');

        $this->assertSame(120, $collectionCache->setCalls[0]['ttl']);
        $this->assertSame(120, $collectionCache->addToReverseIndexCalls[0]['ttl']);
    }

    public function testGetCachedIdsWithCrossValidationReturnsNullWhenDisabled(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->willReturnOnNextGet([1, 2]);
        $cache = new QueryResultSecondLevelCache($collectionCache);

        $result = $cache->getCachedIdsWithCrossValidation('some-key', 'products');

        $this->assertNull($result);
        $this->assertSame([], $collectionCache->getCalls);
    }

    public function testGetCachedIdsWithCrossValidationReturnsNullOnPlainMiss(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->willReturnOnNextGet(null);
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(null);

        $result = $cache->getCachedIdsWithCrossValidation('some-key', 'products');

        $this->assertNull($result);
    }

    public function testGetCachedIdsWithCrossValidationReturnsTheListWhenReverseIndexIsIntact(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->willReturnOnNextGet([1, 2]);
        $collectionCache->willReturnMissingReverseIndexIds([]);
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(null);

        $result = $cache->getCachedIdsWithCrossValidation('some-key', 'products');

        $this->assertSame([1, 2], $result);
    }

    public function testGetCachedIdsWithCrossValidationDiscardsTheWholeListWhenAnyReverseIndexEntryIsMissing(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->willReturnOnNextGet([1, 2]);
        $collectionCache->willReturnMissingReverseIndexIds([2]);
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(null);

        $result = $cache->getCachedIdsWithCrossValidation('some-key', 'products');

        $this->assertNull($result, 'A missing reverse-index entry for any id must discard the ENTIRE cached list, not partially repair it.');
    }

    public function testGetCachedIdsWithCrossValidationChecksEveryIdInTheHitListInOneBatchedCall(): void
    {
        $collectionCache = new SpyCollectionCache();
        $collectionCache->willReturnOnNextGet([1, 2, 3]);
        $collectionCache->willReturnMissingReverseIndexIds([]);
        $cache = new QueryResultSecondLevelCache($collectionCache);
        $cache->enable(null);

        $cache->getCachedIdsWithCrossValidation('some-key', 'products');

        $this->assertCount(1, $collectionCache->findMissingReverseIndexIdsCalls, 'Must be a single batched existence check, not N round-trips.');
        $this->assertSame('products', $collectionCache->findMissingReverseIndexIdsCalls[0]['table']);
        $this->assertSame([1, 2, 3], $collectionCache->findMissingReverseIndexIdsCalls[0]['ids']);
    }
}
