<?php

namespace Articulate\Modules\EntityManager;

use Articulate\Attributes\Property;
use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Attributes\Reflection\ReflectionManyToMany;
use Articulate\Attributes\Reflection\ReflectionMorphedByMany;
use Articulate\Attributes\Reflection\ReflectionMorphToMany;
use Articulate\Attributes\Reflection\ReflectionProperty as ArticulateReflectionProperty;
use Articulate\Attributes\Reflection\ReflectionRelation;
use Articulate\Attributes\Version;
use Articulate\Collection\MappingCollection;
use Articulate\Modules\EntityManager\Proxy\ProxyInterface;
use Articulate\Schema\EntityRegistrarInterface;
use Articulate\Schema\HydratorInterface;
use Articulate\Utils\ReflectionCache;
use Articulate\Utils\TypeRegistry;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class ObjectHydrator implements HydratorInterface {
    private ?RelationshipLoader $relationshipLoader;

    private LifecycleCallbackManager $callbackManager;

    private TypeRegistry $typeRegistry;

    /** @var array<string, true> Classes currently being hydrated (cycle guard) */
    private array $hydrating = [];

    public function __construct(
        private readonly EntityRegistrarInterface $entityRegistrar,
        ?RelationshipLoader $relationshipLoader = null,
        ?LifecycleCallbackManager $callbackManager = null,
        ?TypeRegistry $typeRegistry = null
    ) {
        $this->relationshipLoader = $relationshipLoader;
        $this->callbackManager = $callbackManager ?? new LifecycleCallbackManager();
        $this->typeRegistry = $typeRegistry ?? new TypeRegistry();
    }

    public function hydrate(string $class, array $data, ?object $entity = null, array $with = []): mixed
    {
        // 1. Create entity instance (or use provided)
        $entity ??= $this->createEntity($class);

        // 2. Convert database types to PHP types and set scalar properties
        $this->hydrateProperties($entity, $data);

        // 3. Handle relations (lazy proxies or eager loading)
        $this->hydrateRelations($entity, $data, $with);

        // 4. Register in identity map
        $this->registerEntity($entity, $data);

        // 5. Call postLoad callbacks
        $this->invokePostLoadCallbacks($entity);

        return $entity;
    }

    public function extract(mixed $entity): array
    {
        $data = [];
        $reflection = ReflectionCache::getClass($entity::class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $value = $entity->$name ?? null;

            // Convert PHP types to database types
            $dbValue = $this->convertToDatabase($value, $name, $entity);
            $data[$name] = $dbValue;
        }

        return $data;
    }

    /**
     * Convert database value to PHP type based on property type.
     */
    private function convertToPHP(mixed $dbValue, string $propertyName, object $entity): mixed
    {
        if ($dbValue === null) {
            return null;
        }

        $property = ReflectionCache::getProperty($entity::class, $propertyName);
        $type = $property->getType();

        if (!$type) {
            // No type hint, return as-is
            return $dbValue;
        }

        $phpType = $this->getPHPTypeFromReflection($type);

        // Get converter for this type
        $converter = $this->typeRegistry->getConverter($phpType);
        if ($converter) {
            return $converter->convertToPHP($dbValue);
        }

        // No converter available, return basic type conversion
        return $this->basicTypeConversion($dbValue, $phpType);
    }

    /**
     * Convert PHP value to database representation.
     */
    private function convertToDatabase(mixed $phpValue, string $propertyName, object $entity): mixed
    {
        if ($phpValue === null) {
            return null;
        }

        $property = ReflectionCache::getProperty($entity::class, $propertyName);
        $type = $property->getType();

        if (!$type) {
            // No type hint, return as-is
            return $phpValue;
        }

        $phpType = $this->getPHPTypeFromReflection($type);

        // Get converter for this type
        $converter = $this->typeRegistry->getConverter($phpType);
        if ($converter) {
            return $converter->convertToDatabase($phpValue);
        }

        // No converter available, return basic type conversion
        return $this->basicTypeConversionToDatabase($phpValue, $phpType);
    }

    /**
     * Extract PHP type string from ReflectionType.
     */
    private function getPHPTypeFromReflection(\ReflectionType $type): string
    {
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        if ($type->allowsNull() && !str_starts_with($typeName, '?')) {
            $typeName = '?' . $typeName;
        }

        return $typeName;
    }

    /**
     * Basic type conversion for common types when no converter is available.
     */
    private function basicTypeConversion(mixed $value, string $targetType): mixed
    {
        return match ($targetType) {
            'int', '?int' => is_numeric($value) ? (int) $value : $value,
            'float', '?float' => is_numeric($value) ? (float) $value : $value,
            'string', '?string' => (string) $value,
            'bool', '?bool' => (bool) $value,
            default => $value
        };
    }

    /**
     * Basic type conversion to database (mostly pass-through for common types).
     */
    private function basicTypeConversionToDatabase(mixed $value, string $targetType): mixed
    {
        // For basic types, PHP values are usually already in acceptable database format
        return $value;
    }

    public function hydratePartial(object $entity, array $data): void
    {
        $this->hydrateProperties($entity, $data);
        $this->hydrateRelations($entity, $data, []);
    }

    private function createEntity(string $class): object
    {
        $reflection = ReflectionCache::getClass($class);

        // Create instance without calling constructor
        return $reflection->newInstanceWithoutConstructor();
    }

    private function hydrateProperties(object $entity, array $data): void
    {
        $reflection = ReflectionCache::getClass($entity::class);

        foreach ($data as $columnName => $value) {
            // Try to map column to property
            $propertyName = $this->mapColumnToProperty($reflection, $columnName);

            if ($propertyName && $reflection->hasProperty($propertyName)) {
                $property = ReflectionCache::getProperty($entity::class, $propertyName);

                // Skip class-typed properties (entities/relations) — handled by hydrateRelations()
                // Allow non-built-in types that have a registered converter (e.g. enums, DateTime)
                $type = $property->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $phpType = $this->getPHPTypeFromReflection($type);
                    if ($this->typeRegistry->getConverter($phpType) === null) {
                        continue;
                    }
                }

                $property->setAccessible(true);

                // Convert database value to PHP type
                $phpValue = $this->convertToPHP($value, $propertyName, $entity);
                $property->setValue($entity, $phpValue);
            }
        }
    }

    /**
     * @param string[] $with Relation property names to force-eager even when lazy: true
     */
    private function hydrateRelations(object $entity, array $data, array $with = []): void
    {
        if (!$this->relationshipLoader) {
            return; // No relationship loader configured
        }

        $metadata = $this->relationshipLoader->getMetadataRegistry()->getMetadata($entity::class);
        $em = null;

        $entityClass = $entity::class;
        $this->hydrating[$entityClass] = true;

        try {
            foreach ($metadata->getRelations() as $relationName => $relation) {
                $prop = ReflectionCache::getProperty($entity::class, $relationName);
                $prop->setAccessible(true);

                // ── Determine whether this relation is a collection type ───────────
                $isManyToMany = $relation instanceof ReflectionManyToMany;
                $isMorphManyToMany = $relation instanceof ReflectionMorphToMany || $relation instanceof ReflectionMorphedByMany;
                $isCollectionRelation = $isManyToMany
                    || $isMorphManyToMany
                    || ($relation instanceof ReflectionRelation
                        && ($relation->isOneToMany() || $relation->isManyToMany() || $relation->isMorphMany()));

                if ($prop->isInitialized($entity) && $prop->getValue($entity) !== null) {
                    $existing = $prop->getValue($entity);
                    // For collection relations, skip only if already a proper Collection — not a bare array default.
                    if (!$isCollectionRelation || $existing instanceof Collection) {
                        continue;
                    }
                }

                $isMappingCollectionType = $relation instanceof ReflectionManyToMany && $relation->isMappingCollectionType();

                // If the target entity is already being hydrated up the call stack, force lazy to break the cycle.
                $targetEntity = $relation->getTargetEntity();
                $cycleDetected = $targetEntity !== null && isset($this->hydrating[$targetEntity]);

                if (!$cycleDetected && (!$relation->isLazy() || in_array($relationName, $with, true) || $isMappingCollectionType)) {
                    // Eager: load relation immediately.
                    // MappingCollection-typed properties always load eagerly — LazyCollection can't satisfy their type.
                    $relatedData = $this->relationshipLoader->load($entity, $relation, $data);
                    if (is_array($relatedData)) {
                        if ($isMappingCollectionType) {
                            $relatedData = new MappingCollection($relatedData);
                        } elseif ($isManyToMany || $isMorphManyToMany || ($relation instanceof ReflectionRelation && $relation->isOneToMany())) {
                            $propertyType = $prop->getType();
                            if (!$propertyType instanceof ReflectionNamedType || $propertyType->getName() !== 'array') {
                                $relatedData = (new Collection($relatedData))->markClean();
                            }
                        }
                    }
                    $prop->setValue($entity, $relatedData);

                    continue;
                }

                // ── Lazy relations ────────────────────────────────────────────────
                $em ??= $this->relationshipLoader->getEntityManager();

                if ($isCollectionRelation) {
                    $propertyType = $prop->getType();
                    if ($propertyType instanceof ReflectionNamedType && $propertyType->getName() === 'array') {
                        $prop->setValue($entity, []);

                        continue;
                    }

                    // Collection — wrap in a LazyCollection with optional COUNT optimisation.
                    $loader = fn () => $this->relationshipLoader->load($entity, $relation);
                    $countLoader = ($isManyToMany
                        || ($relation instanceof ReflectionRelation && ($relation->isOneToMany() || $relation->isManyToMany())))
                        ? fn () => $this->relationshipLoader->count($entity, $relation)
                        : null;

                    $prop->setValue($entity, new LazyCollection($loader, $countLoader));
                } elseif ($relation instanceof ReflectionRelation && $relation->isOwningSide()) {
                    // Owning side with FK in row (ManyToOne, owning OneToOne, MorphTo).
                    $fkValue = $data[$relation->getColumnName()] ?? null;
                    if ($fkValue !== null) {
                        $prop->setValue($entity, $em->getReference($relation->getTargetEntity(), $fkValue));
                    }
                } else {
                    // Inverse single entity (inverse OneToOne, MorphOne) — proxy with custom loader.
                    $proxy = $em->createLazyReference(
                        $relation->getTargetEntity(),
                        function (ProxyInterface $p) use ($entity, $relation): void {
                            $loaded = $this->relationshipLoader->load($entity, $relation);
                            if ($loaded !== null) {
                                $ref = ReflectionCache::getClass($loaded::class);
                                foreach ($ref->getProperties() as $rp) {
                                    $rp->setAccessible(true);

                                    try {
                                        $rp->setValue($p, $rp->getValue($loaded));
                                    } catch (\Error) {
                                        // skip read-only or uninitialised properties
                                    }
                                }
                                $p->markProxyInitialized();
                            }
                        }
                    );
                    $prop->setValue($entity, $proxy);
                }
            }
        } finally {
            unset($this->hydrating[$entityClass]);
        }
    }

    private function registerEntity(object $entity, array $data): void
    {
        $this->entityRegistrar->registerManaged($entity, $data);
    }

    private function invokePostLoadCallbacks(object $entity): void
    {
        $this->callbackManager->invokeCallbacks($entity, 'postLoad');
    }

    private function mapColumnToProperty(ReflectionClass $reflection, string $columnName): ?string
    {
        // Simple mapping: snake_case to camelCase
        $propertyName = $this->snakeToCamel($columnName);

        // Check if property exists
        if ($reflection->hasProperty($propertyName)) {
            return $propertyName;
        }

        // Support snake_case property names (e.g. $street_address maps from street_address column)
        if ($propertyName !== $columnName && $reflection->hasProperty($columnName)) {
            return $columnName;
        }

        // Check for explicit column-name mapping on #[Property] or (implies-#[Property]) #[Version]
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(Property::class) as $attribute) {
                if ($attribute->newInstance()->name === $columnName) {
                    return $property->getName();
                }
            }
            foreach ($property->getAttributes(Version::class) as $attribute) {
                if ($attribute->newInstance()->name === $columnName) {
                    return $property->getName();
                }
            }
        }

        return null;
    }

    private function snakeToCamel(string $string): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $string))));
    }

    private function extractEntityId(object $entity, array $data): mixed
    {
        $primaryKeyProperty = $this->findPrimaryKeyProperty($entity);
        if ($primaryKeyProperty !== null) {
            return $primaryKeyProperty->getValue($entity);
        }

        // Fallback: try to get from data array using primary key column name
        $reflectionEntity = new ReflectionEntity($entity::class);
        $primaryKeyColumns = $reflectionEntity->getPrimaryKeyColumns();
        if (!empty($primaryKeyColumns)) {
            $firstKey = $primaryKeyColumns[0];

            return $data[$firstKey] ?? null;
        }

        return null;
    }

    private function findPrimaryKeyProperty(object $entity): ?ArticulateReflectionProperty
    {
        $reflectionEntity = new ReflectionEntity($entity::class);

        foreach (iterator_to_array($reflectionEntity->getEntityProperties()) as $property) {
            if ($property instanceof ArticulateReflectionProperty && $property->isPrimaryKey()) {
                return $property;
            }
        }

        return null;
    }
}
