<?php

namespace Articulate\Utils;

use InvalidArgumentException;

/**
 * Registry for mapping PHP types to database types and vice versa.
 * Supports custom type converters and bidirectional mapping.
 */
class TypeRegistry {
    protected array $phpToDb = [];

    protected array $dbToPhp = [];

    private array $converters = [];

    private array $classMappings = []; // class/interface => ['type' => db_type, 'priority' => int]

    private array $inheritanceCache = []; // class/interface => ['interfaces' => [], 'parents' => []]

    private array $mappingCache = []; // resolved php_type => db_type

    public function __construct()
    {
        $this->registerBuiltInTypes();
    }

    /**
     * Register a PHP type to database type mapping.
     */
    public function registerType(string $phpType, string $dbType, ?TypeConverterInterface $converter = null): void
    {
        $this->phpToDb[$phpType] = $dbType;

        // Only register reverse mapping for non-nullable types to avoid conflicts
        if (!str_starts_with($phpType, '?')) {
            $this->dbToPhp[$dbType] = $phpType;
        }

        if ($converter) {
            $this->converters[$phpType] = $converter;
        }

        // Clear mapping cache when new mappings are registered
        $this->mappingCache = [];
    }

    /**
     * Register a PHP class or interface to database type mapping
     * All classes implementing/extending this will use the mapping.
     *
     * @param int $priority Higher priority mappings are chosen first when multiple apply (default: 0)
     */
    public function registerClassMapping(
        string $classOrInterface,
        string $dbType,
        ?TypeConverterInterface $converter = null,
        int $priority = 0
    ): void {
        if (!class_exists($classOrInterface) && !interface_exists($classOrInterface)) {
            throw new InvalidArgumentException(
                "Cannot register mapping for unknown class or interface: {$classOrInterface}"
            );
        }

        $this->classMappings[$classOrInterface] = [
            'type' => $dbType,
            'priority' => $priority,
        ];

        // Register converter if provided
        if ($converter) {
            $this->converters[$classOrInterface] = $converter;
        }

        // Clear mapping cache when new mappings are registered
        $this->mappingCache = [];
    }

    /**
     * Get database type for a PHP type.
     */
    public function getDatabaseType(string $phpType): string
    {
        // Check cache first
        if (isset($this->mappingCache[$phpType])) {
            return $this->mappingCache[$phpType];
        }

        // First check direct type mappings
        if (isset($this->phpToDb[$phpType])) {
            return $this->mappingCache[$phpType] = $this->phpToDb[$phpType];
        }

        // Check if it's a class that implements/extends registered interfaces/classes
        $mappedType = $this->findClassMapping($phpType);
        if ($mappedType !== null) {
            return $this->mappingCache[$phpType] = $mappedType;
        }

        // Enums map to their backing scalar type
        $enumType = $this->resolveEnumDatabaseType($phpType);
        if ($enumType !== null) {
            return $this->mappingCache[$phpType] = $enumType;
        }

        // Fall back to the type itself (for custom database types)
        return $this->mappingCache[$phpType] = $phpType;
    }

    /**
     * Find database type mapping for a class based on implemented interfaces/extended classes.
     */
    private function findClassMapping(string $className): ?string
    {
        // Get inheritance information (cached)
        $inheritance = $this->getInheritanceInfo($className);

        // Collect all applicable mappings with their priorities
        $candidates = [];

        // Check direct class mapping
        if (isset($this->classMappings[$className])) {
            $candidates[] = $this->classMappings[$className];
        }

        // Check interfaces
        foreach ($inheritance['interfaces'] as $interface) {
            if (isset($this->classMappings[$interface])) {
                $candidates[] = $this->classMappings[$interface];
            }
        }

        // Check parent classes
        foreach ($inheritance['parents'] as $parent) {
            if (isset($this->classMappings[$parent])) {
                $candidates[] = $this->classMappings[$parent];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Return the mapping with highest priority (lowest priority number)
        // If priorities are equal, return the first one found
        usort($candidates, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $candidates[0]['type'];
    }

    /**
     * Get cached inheritance information for a class.
     */
    private function getInheritanceInfo(string $className): array
    {
        if (!isset($this->inheritanceCache[$className])) {
            if (!class_exists($className) && !interface_exists($className)) {
                $this->inheritanceCache[$className] = ['interfaces' => [], 'parents' => []];
            } else {
                $this->inheritanceCache[$className] = [
                    'interfaces' => class_implements($className) ?: [],
                    'parents' => class_parents($className) ?: [],
                ];
            }
        }

        return $this->inheritanceCache[$className];
    }

    /**
     * Get PHP type for a database type.
     */
    public function getPhpType(string $dbType): string
    {
        // Special handling for TINYINT(1) -> bool
        if (preg_match('/TINYINT\(1\)/i', $dbType)) {
            return 'bool';
        }

        // Handle database types with parameters like VARCHAR(255), TINYINT(2)
        $baseType = $this->extractBaseType($dbType);

        return $this->dbToPhp[$baseType] ?? $this->inferPhpType($baseType);
    }

    /**
     * Get type converter for a PHP type.
     */
    public function getConverter(string $phpType): ?TypeConverterInterface
    {
        if (isset($this->converters[$phpType])) {
            return $this->converters[$phpType];
        }

        // Lazily build (and cache) a converter for enum-typed properties
        $className = ltrim($phpType, '?');
        if (enum_exists($className)) {
            return $this->converters[$phpType] = new EnumTypeConverter($className);
        }

        return null;
    }

    /**
     * Resolve the database column type for an enum-typed property, or null if
     * the type is not an enum. Backed int enums → INT, everything else → VARCHAR.
     */
    private function resolveEnumDatabaseType(string $phpType): ?string
    {
        $className = ltrim($phpType, '?');
        if (!enum_exists($className)) {
            return null;
        }

        $reflection = new \ReflectionEnum($className);
        if ($reflection->isBacked() && (string) $reflection->getBackingType() === 'int') {
            return 'INT';
        }

        return 'VARCHAR(255)';
    }

    /**
     * Extract base type from parameterized type like VARCHAR(255).
     */
    protected function extractBaseType(string $dbType): string
    {
        // Handle types like VARCHAR(255), TINYINT(1), etc.
        if (preg_match('/^(\w+)/', strtoupper($dbType), $matches)) {
            return $matches[1];
        }

        return $dbType;
    }

    /**
     * Infer PHP type from database type when no explicit mapping exists.
     */
    protected function inferPhpType(string $dbType): string
    {
        $dbType = strtoupper($dbType);

        return match ($dbType) {
            'INT', 'INTEGER', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT' => 'int',
            'FLOAT', 'DOUBLE', 'DECIMAL', 'NUMERIC' => 'float',
            'VARCHAR', 'CHAR', 'TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT' => 'string',
            'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR' => 'string', // Could be DateTime in future
            'BOOL', 'BOOLEAN' => 'bool',
            default => 'mixed'
        };
    }

    /**
     * Register built-in type mappings.
     */
    protected function registerBuiltInTypes(): void
    {
        // Basic types (register non-nullable first) - using uppercase SQL standard
        $this->registerType('int', 'INT');
        $this->registerType('float', 'FLOAT');
        $this->registerType('string', 'VARCHAR(255)');
        $this->registerType('bool', 'TINYINT(1)', new BoolTypeConverter());
        $this->registerType('mixed', 'TEXT');

        // DateTime types (basic mapping for now)
        $this->registerType('DateTime', 'DATETIME', new DateTimeTypeConverter());
        $this->registerType('DateTimeImmutable', 'DATETIME', new DateTimeTypeConverter(\DateTimeImmutable::class));

        // Example custom spatial type (requires spatial extensions)
        $this->registerType(Point::class, 'POINT', new PointTypeConverter());

        // Class/Interface mappings
        $this->registerClassMapping(\DateTimeInterface::class, 'DATETIME', new DateTimeTypeConverter(), 10); // High priority for DateTime

        // JSON/array type
        $this->registerType('array', 'JSON', new JsonTypeConverter());
        $this->registerType('?array', 'JSON', new JsonTypeConverter());

        // Nullable versions (these override the db->php mapping for reverse lookups)
        $this->registerType('?int', 'INT');
        $this->registerType('?float', 'FLOAT');
        $this->registerType('?string', 'VARCHAR(255)');
        $this->registerType('?bool', 'TINYINT(1)', new BoolTypeConverter());
        $this->registerType('?DateTime', 'DATETIME', new DateTimeTypeConverter());
        $this->registerType('?DateTimeImmutable', 'DATETIME', new DateTimeTypeConverter(\DateTimeImmutable::class));
        $this->registerType('?' . Point::class, 'POINT', new PointTypeConverter());
    }
}
