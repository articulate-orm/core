<?php

namespace Articulate\Modules\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6-backed storage for the collection (query-region) cache.
 *
 * Key prefixes:
 *  - `l2q_`    id-lists matched by a `cacheable()` query
 *  - `l2qgen_` table generation counters (hash of namespace + table -> int, randomly seeded).
 *
 * Prefixes avoid the PSR-6 reserved characters {}()/\@: so real pools accept the keys.
 *
 * Distinct from SecondLevelCache::generateKey()'s plain per-entity hash —
 * this module never stores row data, only id-lists and table generations.
 */
class CollectionCache implements CollectionCacheInterface {
    private const ID_LIST_PREFIX = 'l2q_';

    private const TABLE_GENERATION_PREFIX = 'l2qgen_';

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $namespace = '',
    ) {
    }

    public function getIdList(string $key): ?array
    {
        try {
            $item = $this->pool->getItem(self::ID_LIST_PREFIX . $key);

            return $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function setIdList(string $key, array $ids, int $ttl): void
    {
        try {
            $item = $this->pool->getItem(self::ID_LIST_PREFIX . $key);
            $item->set($ids)->expiresAfter($ttl);
            $this->pool->save($item);
        } catch (\Throwable) {
            // Cache failure never breaks query execution — fail open to a database read.
        }
    }

    public function getTableGenerations(array $tableNames): ?array
    {
        if ($tableNames === []) {
            return [];
        }

        try {
            // "shop.products" and "products" share one counter, so several names can map to a key
            $keyToTables = [];
            foreach ($tableNames as $tableName) {
                $keyToTables[$this->tableGenerationKey($tableName)][] = $tableName;
            }

            $generations = [];
            $read = 0;
            foreach ($this->pool->getItems(array_keys($keyToTables)) as $key => $item) {
                if ($item->isHit()) {
                    $generation = (int) $item->get();
                } else {
                    $generation = $this->seedGeneration();
                    $item->set($generation);
                    $this->pool->save($item);
                }
                foreach ($keyToTables[$key] as $tableName) {
                    $generations[$tableName] = $generation;
                }
                $read++;
            }

            return $read === count($keyToTables) ? $generations : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function incrementTableGenerations(array $tableNames): void
    {
        if ($tableNames === []) {
            return;
        }

        try {
            $keys = array_map(fn (string $tableName): string => $this->tableGenerationKey($tableName), $tableNames);
            foreach ($this->pool->getItems(array_values(array_unique($keys))) as $item) {
                $item->set($item->isHit() ? (int) $item->get() + 1 : $this->seedGeneration());
                $this->pool->save($item);
            }
        } catch (\Throwable) {
            // Cache failure never breaks query execution — fail open to a database read.
        }
    }

    /**
     * A counter the backend dropped while older id-lists survived must not
     * restart at a value those lists were keyed against, so a missing counter
     * starts from a random point rather than 0. Capped well below PHP_INT_MAX
     * so increments never overflow.
     */
    private function seedGeneration(): int
    {
        return random_int(1, 1 << 53);
    }

    /**
     * The unit of work bumps bare entity table names, while from()/join()/dependsOn
     * may name a table schema-qualified ("shop.products"). Dropping the schema
     * makes both land on one counter. Same-named tables in different schemas
     * then share a counter: over-invalidation, never a stale list.
     */
    private function tableGenerationKey(string $tableName): string
    {
        $tableName = preg_replace('/^[A-Za-z_]\w*\.(?=[A-Za-z_]\w*$)/', '', $tableName) ?? $tableName;

        return self::TABLE_GENERATION_PREFIX . hash('sha256', $this->namespace . "\0" . $tableName);
    }
}
