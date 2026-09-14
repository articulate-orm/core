<?php

namespace Articulate\Schema;

use Articulate\Attributes\Indexes\AutoIncrement;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Attributes\Reflection\ReflectionProperty;
use Articulate\Attributes\Reflection\ReflectionRelation;
use Articulate\Attributes\Reflection\RelationInterface;
use Articulate\Attributes\SoftDeleteable;
use Articulate\Attributes\VersionAware;

/**
 * Entity metadata containing all information about an entity class
 * extracted from attributes and reflection.
 */
class EntityMetadata {
    private ReflectionEntity $reflectionEntity;

    private ?string $tableName = null;

    private ?string $repositoryClass = null;

    private array $properties = [];

    private array $relations = [];

    private array $primaryKeyColumns = [];

    private ?SoftDeleteable $softDeleteable = null;

    private ?ReflectionProperty $versionProperty = null;

    private ?VersionAware $versionAware = null;

    public function __construct(string $entityClass)
    {
        $this->reflectionEntity = new ReflectionEntity($entityClass);
        $this->loadMetadata();
    }

    /**
     * ReflectionEntity extends the native, non-serializable \ReflectionClass —
     * store the derived, plain metadata instead and skip loadMetadata() on wakeup.
     *
     * @return array{
     *     entityClass: class-string,
     *     tableName: ?string,
     *     repositoryClass: ?string,
     *     properties: array<string, ReflectionProperty>,
     *     relations: array<string, RelationInterface>,
     *     primaryKeyColumns: array<int, string>,
     *     softDeleteable: ?SoftDeleteable,
     *     versionProperty: ?ReflectionProperty,
     *     versionAware: ?VersionAware,
     * }
     */
    public function __serialize(): array
    {
        return [
            'entityClass' => $this->reflectionEntity->getName(),
            'tableName' => $this->tableName,
            'repositoryClass' => $this->repositoryClass,
            'properties' => $this->properties,
            'relations' => $this->relations,
            'primaryKeyColumns' => $this->primaryKeyColumns,
            'softDeleteable' => $this->softDeleteable,
            'versionProperty' => $this->versionProperty,
            'versionAware' => $this->versionAware,
        ];
    }

    /**
     * @param array{
     *     entityClass: class-string,
     *     tableName: ?string,
     *     repositoryClass: ?string,
     *     properties: array<string, ReflectionProperty>,
     *     relations: array<string, RelationInterface>,
     *     primaryKeyColumns: array<int, string>,
     *     softDeleteable: ?SoftDeleteable,
     *     versionProperty: ?ReflectionProperty,
     *     versionAware: ?VersionAware,
     * } $data
     */
    public function __unserialize(array $data): void
    {
        $this->reflectionEntity = new ReflectionEntity($data['entityClass']);
        $this->tableName = $data['tableName'];
        $this->repositoryClass = $data['repositoryClass'];
        $this->properties = $data['properties'];
        $this->relations = $data['relations'];
        $this->primaryKeyColumns = $data['primaryKeyColumns'];
        $this->softDeleteable = $data['softDeleteable'];
        $this->versionProperty = $data['versionProperty'];
        $this->versionAware = $data['versionAware'];
    }

    /**
     * Load all metadata from the entity class.
     */
    private function loadMetadata(): void
    {
        if (!$this->reflectionEntity->isEntity()) {
            throw new \InvalidArgumentException("Class {$this->reflectionEntity->getName()} is not an entity");
        }

        $this->tableName = $this->reflectionEntity->getTableName();
        $this->repositoryClass = $this->reflectionEntity->getRepositoryClass();
        $this->primaryKeyColumns = $this->reflectionEntity->getPrimaryKeyColumns();

        // Load properties from getEntityProperties (includes Property attributes)
        foreach ($this->reflectionEntity->getEntityProperties() as $property) {
            if ($property instanceof ReflectionProperty) {
                $this->properties[$property->getFieldName()] = $property;
            }
        }

        // Also load properties that have PrimaryKey but no Property attribute
        foreach ($this->reflectionEntity->getProperties() as $reflectionProperty) {
            $propertyName = $reflectionProperty->getName();

            // Skip if already loaded
            if (isset($this->properties[$propertyName])) {
                continue;
            }

            // Check if it has PrimaryKey attribute
            $primaryKeyAttributes = $reflectionProperty->getAttributes(PrimaryKey::class);
            if (!empty($primaryKeyAttributes)) {
                // Create a ReflectionProperty for primary key properties
                $propertyAttribute = $reflectionProperty->getAttributes(Property::class);
                $propertyInstance = !empty($propertyAttribute)
                    ? $propertyAttribute[0]->newInstance()
                    : new Property(); // Default property

                $primaryKeyInstance = $primaryKeyAttributes[0]->newInstance();
                $reflectionPropertyObj = new ReflectionProperty(
                    $propertyInstance,
                    $reflectionProperty,
                    isset($reflectionProperty->getAttributes(AutoIncrement::class)[0]),
                    true, // is primary key
                    $primaryKeyInstance->generator,
                    $primaryKeyInstance->sequence,
                    $primaryKeyInstance->options,
                );

                $this->properties[$propertyName] = $reflectionPropertyObj;
            }
        }

        // Load all relations (including inverse sides)
        foreach ($this->reflectionEntity->getEntityRelationProperties() as $relation) {
            $this->relations[$relation->getPropertyName()] = $relation;
        }

        // Load soft-delete configuration
        $this->softDeleteable = $this->reflectionEntity->getSoftDeleteableAttribute();

        // Load optimistic-locking configuration. #[VersionAware] is an inert
        // acknowledgement marker — no SET/WHERE/bump — so a column appearing in
        // both a class's own #[Version] and its own #[VersionAware] is merely
        // redundant, not a contradiction.
        $this->versionProperty = $this->reflectionEntity->getVersionProperty();
        $this->versionAware = $this->reflectionEntity->getVersionAwareAttribute();
    }

    /**
     * Get the table name for this entity.
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the repository class for this entity.
     */
    public function getRepositoryClass(): ?string
    {
        return $this->repositoryClass;
    }

    /**
     * Get all property metadata.
     * @return ReflectionProperty[]
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Get a specific property by name.
     */
    public function getProperty(string $propertyName): ?ReflectionProperty
    {
        return $this->properties[$propertyName] ?? null;
    }

    /**
     * Get all relation metadata.
     * @return array<string, RelationInterface>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * @return ReflectionRelation[]
     */
    public function getColumnRelations(): array
    {
        $result = [];
        foreach ($this->reflectionEntity->getColumnRelationProperties() as $relation) {
            $result[$relation->getPropertyName()] = $relation;
        }

        return $result;
    }

    /**
     * Get a specific relation by name.
     */
    public function getRelation(string $relationName): ?RelationInterface
    {
        return $this->relations[$relationName] ?? null;
    }

    /**
     * Get primary key column names.
     */
    public function getPrimaryKeyColumns(): array
    {
        return $this->primaryKeyColumns;
    }

    /**
     * Get the entity class name.
     */
    public function getClassName(): string
    {
        return $this->reflectionEntity->getName();
    }

    /**
     * Check if a property exists.
     */
    public function hasProperty(string $propertyName): bool
    {
        return isset($this->properties[$propertyName]);
    }

    /**
     * Check if a relation exists.
     */
    public function hasRelation(string $relationName): bool
    {
        return isset($this->relations[$relationName]);
    }

    /**
     * Get column name for a property.
     */
    public function getColumnName(string $propertyName): ?string
    {
        $property = $this->getProperty($propertyName);

        return $property ? $property->getColumnName() : null;
    }

    /**
     * Get property name for a column.
     */
    public function getPropertyNameForColumn(string $columnName): ?string
    {
        foreach ($this->properties as $propertyName => $property) {
            if ($property->getColumnName() === $columnName) {
                return $propertyName;
            }
        }

        return null;
    }

    /**
     * Get all column names for this entity.
     */
    public function getColumnNames(): array
    {
        $columns = [];
        foreach ($this->properties as $property) {
            $columns[] = $property->getColumnName();
        }

        return $columns;
    }

    /**
     * Check if this entity is soft-deleteable.
     */
    public function isSoftDeleteable(): bool
    {
        return $this->softDeleteable !== null;
    }

    /**
     * Get the soft-delete column name.
     */
    public function getSoftDeleteColumn(): ?string
    {
        return $this->softDeleteable?->columnName;
    }

    /**
     * Get the soft-delete field name.
     */
    public function getSoftDeleteField(): ?string
    {
        return $this->softDeleteable?->fieldName;
    }

    /**
     * The single version column this slice bumps and checks on UPDATE: its own
     * #[Version] property's column, or nothing. #[VersionAware] contributes
     * nothing here — it is an inert acknowledgement marker, not a runtime
     * participant. Bump list and check list are now identical, so the former
     * getVersionColumns()/getCheckedVersionColumns() pair collapses to this.
     *
     * @return string[] zero or one column
     */
    public function getVersionColumns(): array
    {
        return $this->versionProperty !== null ? [$this->versionProperty->getColumnName()] : [];
    }

    /**
     * The version columns this slice acknowledges writing without checking, via
     * its own #[VersionAware] list. Validate-time only — no runtime path reads
     * this.
     *
     * @return string[]
     */
    public function getAcknowledgedVersionColumns(): array
    {
        return $this->versionAware !== null ? array_values($this->versionAware->columns) : [];
    }

    /**
     * This slice's guard set: the persisted #[Property] columns it declares,
     * excluding the primary key and its own #[Version] column. A #[Version]
     * column protects exactly these columns against a lost update. Validate-time
     * only — flush code never groups by table.
     *
     * @return string[]
     */
    public function getGuardSet(): array
    {
        $excluded = $this->primaryKeyColumns;

        if ($this->versionProperty !== null) {
            $excluded[] = $this->versionProperty->getColumnName();
        }

        return array_values(array_diff($this->getColumnNames(), $excluded));
    }
}
