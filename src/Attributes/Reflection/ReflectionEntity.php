<?php

namespace Articulate\Attributes\Reflection;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\AutoIncrement;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToMany;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Attributes\Relations\MorphedByMany;
use Articulate\Attributes\Relations\MorphMany;
use Articulate\Attributes\Relations\MorphOne;
use Articulate\Attributes\Relations\MorphTo;
use Articulate\Attributes\Relations\MorphToMany;
use Articulate\Attributes\Relations\OneToMany;
use Articulate\Attributes\Relations\OneToOne;
use Articulate\Attributes\SoftDeleteable;
use Articulate\Attributes\Version;
use Articulate\Attributes\VersionAware;
use Articulate\Schema\SchemaNaming;
use Articulate\Utils\StringUtils;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;

class ReflectionEntity extends ReflectionClass {
    public function __construct(
        string $objectOrClass,
        private readonly SchemaNaming $schemaNaming = new SchemaNaming(),
    ) {
        parent::__construct($objectOrClass);
    }

    public function isEntity(): bool
    {
        return !empty($this->getAttributes(Entity::class));
    }

    public function getEntityProperties(): iterable
    {
        if (!$this->isEntity()) {
            yield from [];

            return;
        }

        foreach ($this->getProperties() as $property) {
            if ($result = $this->processPropertyAttribute($property)) {
                yield $result;

                continue;
            }
            if ($result = $this->processOneToOneAttribute($property)) {
                yield $result;

                continue;
            }
            if ($result = $this->processManyToOneAttribute($property)) {
                yield $result;

                continue;
            }
            if ($result = $this->processMorphToAttribute($property)) {
                yield $result;

                continue;
            }
            if ($result = $this->processMorphOneAttribute($property)) {
                yield $result;

                continue;
            }
            if ($result = $this->processMorphManyAttribute($property)) {
                yield $result;

                continue;
            }
        }
    }

    /**
     * Processes Property and PrimaryKey attributes for a given property.
     * Returns the ReflectionProperty if found, null otherwise.
     */
    private function processPropertyAttribute(\ReflectionProperty $property): ?ReflectionProperty
    {
        /** @var ReflectionAttribute<Property>[] $entityProperty */
        $entityProperty = $property->getAttributes(Property::class, ReflectionAttribute::IS_INSTANCEOF);
        /** @var ReflectionAttribute<PrimaryKey>[] $primaryKeyProperty */
        $primaryKeyProperty = $property->getAttributes(PrimaryKey::class);
        /** @var ReflectionAttribute<Version>[] $versionProperty */
        $versionProperty = $property->getAttributes(Version::class);

        // A property is an entity property if it has Property, PrimaryKey, or Version — #[Version] implies #[Property].
        if (empty($entityProperty) && empty($primaryKeyProperty) && empty($versionProperty)) {
            return null;
        }

        $propertyAttribute = $this->resolvePropertyAttribute($entityProperty, $primaryKeyProperty, $versionProperty);
        $isPrimaryKey = $this->determineIsPrimaryKey($primaryKeyProperty, $propertyAttribute);

        // #[Version]'s explicit name overrides the naming convention when #[Property] left it unset.
        if ($propertyAttribute->name === null && !empty($versionProperty)) {
            $propertyAttribute->name = $versionProperty[0]->newInstance()->name;
        }

        // A freshly-inserted entity's in-memory #[Version] property starts at 0 (like any
        // un-set int property), so the migration-generated column default must match.
        if ($propertyAttribute->defaultValue === null && !empty($versionProperty)) {
            $propertyAttribute->defaultValue = '0';
        }

        [$generatorType, $sequence, $generatorOptions] = $this->extractGeneratorInfo($primaryKeyProperty, $propertyAttribute, $isPrimaryKey);

        return new ReflectionProperty(
            $propertyAttribute,
            $property,
            isset($property->getAttributes(AutoIncrement::class)[0]),
            $isPrimaryKey,
            $generatorType,
            $sequence,
            $generatorOptions,
        );
    }

    /**
     * Resolves which property attribute to use based on available attributes.
     *
     * @param array<ReflectionAttribute<Version>> $versionProperty
     */
    private function resolvePropertyAttribute(array $entityProperty, array $primaryKeyProperty, array $versionProperty = []): Property
    {
        // Find the explicit Property attribute (not PrimaryKey)
        $explicitProperty = null;
        foreach ($entityProperty as $attr) {
            $instance = $attr->newInstance();
            if (!$instance instanceof PrimaryKey) {
                $explicitProperty = $instance;

                break;
            }
        }

        // Use explicit Property if available, otherwise use PrimaryKey
        if ($explicitProperty !== null) {
            return $explicitProperty;
        }

        if (!empty($primaryKeyProperty)) {
            return $primaryKeyProperty[0]->newInstance();
        }

        if (!empty($entityProperty)) {
            return $entityProperty[0]->newInstance();
        }

        // Bare #[Version]: implies a plain #[Property].
        return new Property();
    }

    /**
     * Determines if a property is a primary key.
     */
    private function determineIsPrimaryKey(array $primaryKeyProperty, Property $propertyAttribute): bool
    {
        return !empty($primaryKeyProperty) || $propertyAttribute instanceof PrimaryKey;
    }

    /**
     * Extracts generator information from primary key attributes.
     */
    private function extractGeneratorInfo(array $primaryKeyProperty, Property $propertyAttribute, bool $isPrimaryKey): array
    {
        if (!$isPrimaryKey) {
            return [null, null, null];
        }

        // Get generator info from PrimaryKey attribute
        $primaryKeyInstance = !empty($primaryKeyProperty)
            ? $primaryKeyProperty[0]->newInstance()
            : ($propertyAttribute instanceof PrimaryKey ? $propertyAttribute : null);

        if (!$primaryKeyInstance) {
            return [null, null, null];
        }

        return [
            $primaryKeyInstance->generator,
            $primaryKeyInstance->sequence,
            $primaryKeyInstance->options,
        ];
    }

    /**
     * Processes OneToOne attributes for a given property.
     * Returns the ReflectionRelation if found, null otherwise.
     */
    private function processOneToOneAttribute(\ReflectionProperty $property): ?ReflectionRelation
    {
        /** @var ReflectionAttribute<OneToOne>[] $entityProperty */
        $entityProperty = $property->getAttributes(OneToOne::class);
        if (empty($entityProperty)) {
            return null;
        }

        $relation = new ReflectionRelation($entityProperty[0]->newInstance(), $property, $this->schemaNaming);
        if (!$relation->isOwningSide()) {
            return null;
        }

        return $relation;
    }

    /**
     * Processes ManyToOne attributes for a given property.
     * Returns the ReflectionRelation if found, null otherwise.
     */
    private function processManyToOneAttribute(\ReflectionProperty $property): ?ReflectionRelation
    {
        /** @var ReflectionAttribute<ManyToOne>[] $entityProperty */
        $entityProperty = $property->getAttributes(ManyToOne::class);
        if (empty($entityProperty)) {
            return null;
        }

        $propertyInstance = $entityProperty[0]->newInstance();

        return new ReflectionRelation($propertyInstance, $property, $this->schemaNaming);
    }

    /**
     * Processes MorphTo attributes for a given property.
     * Returns the ReflectionRelation if found, null otherwise.
     */
    private function processMorphToAttribute(\ReflectionProperty $property): ?ReflectionRelation
    {
        /** @var ReflectionAttribute<MorphTo>[] $entityProperty */
        $entityProperty = $property->getAttributes(MorphTo::class);
        if (empty($entityProperty)) {
            return null;
        }

        $propertyInstance = $entityProperty[0]->newInstance();

        return new ReflectionRelation($propertyInstance, $property, $this->schemaNaming);
    }

    /**
     * Processes MorphOne attributes for a given property.
     * Returns the ReflectionRelation if found, null otherwise.
     */
    private function processMorphOneAttribute(\ReflectionProperty $property): ?ReflectionRelation
    {
        /** @var ReflectionAttribute<MorphOne>[] $entityProperty */
        $entityProperty = $property->getAttributes(MorphOne::class);
        if (empty($entityProperty)) {
            return null;
        }

        $relation = new ReflectionRelation($entityProperty[0]->newInstance(), $property, $this->schemaNaming);
        if (!$relation->isOwningSide()) {
            return null;
        }

        return $relation;
    }

    /**
     * Processes MorphMany attributes for a given property.
     * Returns the ReflectionRelation if found, null otherwise.
     */
    private function processMorphManyAttribute(\ReflectionProperty $property): ?ReflectionRelation
    {
        /** @var ReflectionAttribute<MorphMany>[] $entityProperty */
        $entityProperty = $property->getAttributes(MorphMany::class);
        if (empty($entityProperty)) {
            return null;
        }

        $relation = new ReflectionRelation($entityProperty[0]->newInstance(), $property, $this->schemaNaming);
        if (!$relation->isOwningSide()) {
            return null;
        }

        return $relation;
    }

    public function getEntityFieldsProperties(): iterable
    {
        if (!$this->isEntity()) {
            yield from [];

            return;
        }

        foreach ($this->getProperties() as $property) {
            if ($result = $this->processOneToOneAttribute($property)) {
                yield $result;

                continue;
            }

            if ($result = $this->processPropertyAttribute($property)) {
                yield $result;
            }
        }
    }

    public function getEntityRelationProperties(): iterable
    {
        if (!$this->isEntity()) {
            yield from [];

            return;
        }
        foreach ($this->getProperties() as $property) {
            /** @var ReflectionAttribute<OneToOne>[] $oneToOne */
            $oneToOne = $property->getAttributes(OneToOne::class);
            if (!empty($oneToOne)) {
                yield new ReflectionRelation($oneToOne[0]->newInstance(), $property, $this->schemaNaming);
            }

            /** @var ReflectionAttribute<ManyToOne>[] $manyToOne */
            $manyToOne = $property->getAttributes(ManyToOne::class);
            if (!empty($manyToOne)) {
                yield new ReflectionRelation($manyToOne[0]->newInstance(), $property, $this->schemaNaming);
            }

            /** @var ReflectionAttribute<OneToMany>[] $oneToMany */
            $oneToMany = $property->getAttributes(OneToMany::class);
            if (!empty($oneToMany)) {
                yield new ReflectionRelation($oneToMany[0]->newInstance(), $property, $this->schemaNaming);
            }

            /** @var ReflectionAttribute<ManyToMany>[] $manyToMany */
            $manyToMany = $property->getAttributes(ManyToMany::class);
            if (!empty($manyToMany)) {
                yield new ReflectionManyToMany($manyToMany[0]->newInstance(), $property, $this->schemaNaming);
            }

            /** @var ReflectionAttribute<MorphToMany>[] $morphToMany */
            $morphToMany = $property->getAttributes(MorphToMany::class);
            if (!empty($morphToMany)) {
                yield new ReflectionMorphToMany($morphToMany[0]->newInstance(), $property);
            }

            /** @var ReflectionAttribute<MorphedByMany>[] $morphedByMany */
            $morphedByMany = $property->getAttributes(MorphedByMany::class);
            if (!empty($morphedByMany)) {
                yield new ReflectionMorphedByMany($morphedByMany[0]->newInstance(), $property, $this->schemaNaming);
            }

            /** @var ReflectionAttribute<MorphOne>[] $morphOne */
            $morphOne = $property->getAttributes(MorphOne::class);
            if (!empty($morphOne)) {
                yield new ReflectionRelation($morphOne[0]->newInstance(), $property, $this->schemaNaming);
            }

            /** @var ReflectionAttribute<MorphMany>[] $morphMany */
            $morphMany = $property->getAttributes(MorphMany::class);
            if (!empty($morphMany)) {
                yield new ReflectionRelation($morphMany[0]->newInstance(), $property, $this->schemaNaming);
            }

            /** @var ReflectionAttribute<MorphTo>[] $morphTo */
            $morphTo = $property->getAttributes(MorphTo::class);
            if (!empty($morphTo)) {
                yield new ReflectionRelation($morphTo[0]->newInstance(), $property, $this->schemaNaming);
            }
        }
    }

    /**
     * @return iterable<ReflectionRelation>
     */
    public function getColumnRelationProperties(): iterable
    {
        foreach ($this->getEntityRelationProperties() as $relation) {
            if ($relation instanceof ReflectionRelation) {
                yield $relation;
            }
        }
    }

    public function getPrimaryKeyColumns(): array
    {
        if (!$this->isEntity()) {
            return [];
        }
        $columns = [];
        foreach ($this->getProperties() as $property) {
            $primaryKeyAttributes = $property->getAttributes(PrimaryKey::class);
            if (!empty($primaryKeyAttributes)) {
                $primaryKeyAttribute = $primaryKeyAttributes[0]->newInstance();
                $reflectionProperty = new ReflectionProperty($primaryKeyAttribute, $property);
                $columns[] = $reflectionProperty->getColumnName();
            }
        }
        sort($columns);

        return $columns;
    }

    public function getTableName()
    {
        if (!$this->isEntity()) {
            return null;
        }

        return $this->getAttributes(Entity::class)[0]->newInstance()->tableName ?? $this->parseTableName();
    }

    public function getRepositoryClass(): ?string
    {
        if (!$this->isEntity()) {
            return null;
        }

        return $this->getAttributes(Entity::class)[0]->newInstance()->repositoryClass;
    }

    public function isReadOnly(): bool
    {
        if (!$this->isEntity()) {
            return false;
        }

        return $this->getAttributes(Entity::class)[0]->newInstance()->readOnly;
    }

    private function parseTableName(): string
    {
        $className = explode('\\', $this->getName());

        return StringUtils::snakeCase(end($className));
    }

    public function getSoftDeleteableAttribute(): ?SoftDeleteable
    {
        if (!$this->isEntity()) {
            return null;
        }

        $attributes = $this->getAttributes(SoftDeleteable::class);
        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Finds this class's own #[Version] property, if any.
     */
    public function getVersionProperty(): ?ReflectionProperty
    {
        if (!$this->isEntity()) {
            return null;
        }

        foreach ($this->getProperties() as $property) {
            $versionAttributes = $property->getAttributes(Version::class);
            if (empty($versionAttributes)) {
                continue;
            }

            $type = $property->getType();
            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'int') {
                throw new \InvalidArgumentException(sprintf(
                    '#[Version] property "%s::%s" must be typed int.',
                    $this->getName(),
                    $property->getName(),
                ));
            }

            $propertyAttributes = $property->getAttributes(Property::class, ReflectionAttribute::IS_INSTANCEOF);
            $propertyInstance = !empty($propertyAttributes)
                ? $propertyAttributes[0]->newInstance()
                : new Property();

            if ($propertyInstance->name === null) {
                $propertyInstance->name = $versionAttributes[0]->newInstance()->name;
            }
            if ($propertyInstance->defaultValue === null) {
                $propertyInstance->defaultValue = '0';
            }

            $primaryKeyAttributes = $property->getAttributes(PrimaryKey::class);

            return new ReflectionProperty(
                $propertyInstance,
                $property,
                !empty($property->getAttributes(AutoIncrement::class)),
                !empty($primaryKeyAttributes),
            );
        }

        return null;
    }

    /**
     * This class's own #[VersionAware] declaration, if any.
     */
    public function getVersionAwareAttribute(): ?VersionAware
    {
        if (!$this->isEntity()) {
            return null;
        }

        $attributes = $this->getAttributes(VersionAware::class);
        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
