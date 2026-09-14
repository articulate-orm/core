<?php

namespace Articulate\Schema;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Registry for caching and accessing entity metadata.
 */
class EntityMetadataRegistry {
    /** @var array<string, EntityMetadata> */
    private array $metadataCache = [];

    /** @var array<string, list<string>> table name → entity class list */
    private array $tableIndex = [];

    public function __construct(
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /**
     * Get metadata for an entity class.
     */
    public function getMetadata(string $entityClass): EntityMetadata
    {
        if (!isset($this->metadataCache[$entityClass])) {
            $metadata = $this->loadFromCacheOrCompute($entityClass);
            $this->metadataCache[$entityClass] = $metadata;
            $table = $metadata->getTableName();
            if (!in_array($entityClass, $this->tableIndex[$table] ?? [], true)) {
                $this->tableIndex[$table][] = $entityClass;
            }
        }

        return $this->metadataCache[$entityClass];
    }

    /**
     * Reads a computed EntityMetadata from the pool if present, otherwise
     * computes it and persists it. Metadata doesn't change at runtime, so
     * entries never expire on their own — mapping changes require an explicit
     * clearMetadata()/clearAll() call. Cache faults never break resolution.
     */
    private function loadFromCacheOrCompute(string $entityClass): EntityMetadata
    {
        if ($this->cache === null) {
            return new EntityMetadata($entityClass);
        }

        try {
            $item = $this->cache->getItem($this->cacheKey($entityClass));
            if ($item->isHit()) {
                return $item->get();
            }
        } catch (\Throwable) {
            return new EntityMetadata($entityClass);
        }

        $metadata = new EntityMetadata($entityClass);

        try {
            $item->set($metadata);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Cache failure never breaks metadata resolution.
        }

        return $metadata;
    }

    private function cacheKey(string $entityClass): string
    {
        return 'metadata_' . str_replace('\\', '_', $entityClass);
    }

    /**
     * Return all entity classes known to map to the given table.
     * Only classes whose metadata has been loaded at least once are returned.
     *
     * @return list<string>
     */
    public function getClassesByTable(string $tableName): array
    {
        return $this->tableIndex[$tableName] ?? [];
    }

    /**
     * Check if metadata exists for an entity class.
     */
    public function hasMetadata(string $entityClass): bool
    {
        return isset($this->metadataCache[$entityClass]);
    }

    /**
     * Clear cached metadata for a specific entity class.
     */
    public function clearMetadata(string $entityClass): void
    {
        if (isset($this->metadataCache[$entityClass])) {
            $table = $this->metadataCache[$entityClass]->getTableName();
            $this->tableIndex[$table] = array_values(
                array_filter($this->tableIndex[$table] ?? [], fn ($c) => $c !== $entityClass)
            );
        }
        unset($this->metadataCache[$entityClass]);

        try {
            $this->cache?->deleteItem($this->cacheKey($entityClass));
        } catch (\Throwable) {
            // Cache failure never breaks metadata invalidation.
        }
    }

    /**
     * Clear all cached metadata.
     */
    public function clearAll(): void
    {
        $this->metadataCache = [];
        $this->tableIndex = [];

        try {
            $this->cache?->clear();
        } catch (\Throwable) {
            // Cache failure never breaks metadata invalidation.
        }
    }

    /**
     * Get table name for an entity class.
     */
    public function getTableName(string $entityClass): string
    {
        return $this->getMetadata($entityClass)->getTableName();
    }

    /**
     * Check if a class is an entity.
     */
    public function isEntity(string $entityClass): bool
    {
        try {
            $this->getMetadata($entityClass);

            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
