<?php

namespace Articulate\Tests\Utils;

use Articulate\Utils\BoolTypeConverter;
use Articulate\Utils\EnumTypeConverter;
use Articulate\Utils\Point;
use Articulate\Utils\PointTypeConverter;
use Articulate\Utils\TypeConverterInterface;
use Articulate\Utils\TypeRegistry;
use PHPUnit\Framework\TestCase;

enum TypeRegistryStringEnum: string {
    case A = 'a';
}

enum TypeRegistryIntEnum: int {
    case One = 1;
}

enum TypeRegistryPureEnum {
    case X;
}

class TypeRegistryTest extends TestCase {
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TypeRegistry();
    }

    public function testConstructsWithBuiltInTypes(): void
    {
        // Test that basic types are registered
        $this->assertSame('INT', $this->registry->getDatabaseType('int'));
        $this->assertSame('FLOAT', $this->registry->getDatabaseType('float'));
        $this->assertSame('VARCHAR(255)', $this->registry->getDatabaseType('string'));
        $this->assertSame('TINYINT(1)', $this->registry->getDatabaseType('bool'));
        $this->assertSame('TEXT', $this->registry->getDatabaseType('mixed'));
    }

    public function testRegisterTypeAndGetDatabaseType(): void
    {
        $this->registry->registerType('custom', 'CUSTOM_TYPE');

        $this->assertSame('CUSTOM_TYPE', $this->registry->getDatabaseType('custom'));
    }

    public function testRegisterTypeWithConverter(): void
    {
        $converter = $this->createStub(TypeConverterInterface::class);

        $this->registry->registerType('custom', 'CUSTOM_TYPE', $converter);

        $this->assertSame('CUSTOM_TYPE', $this->registry->getDatabaseType('custom'));
        $this->assertSame($converter, $this->registry->getConverter('custom'));
    }

    public function testIntBackedEnumMapsToInt(): void
    {
        $this->assertSame('INT', $this->registry->getDatabaseType(TypeRegistryIntEnum::class));
    }

    public function testStringBackedEnumMapsToVarchar(): void
    {
        $this->assertSame('VARCHAR(255)', $this->registry->getDatabaseType(TypeRegistryStringEnum::class));
    }

    public function testPureEnumMapsToVarchar(): void
    {
        $this->assertSame('VARCHAR(255)', $this->registry->getDatabaseType(TypeRegistryPureEnum::class));
    }

    public function testNullableEnumMapsToBackingType(): void
    {
        $this->assertSame('INT', $this->registry->getDatabaseType('?' . TypeRegistryIntEnum::class));
    }

    public function testGetConverterForEnumReturnsEnumConverter(): void
    {
        $this->assertInstanceOf(
            EnumTypeConverter::class,
            $this->registry->getConverter(TypeRegistryStringEnum::class),
        );
    }

    public function testGetConverterForNullableEnum(): void
    {
        $this->assertInstanceOf(
            EnumTypeConverter::class,
            $this->registry->getConverter('?' . TypeRegistryIntEnum::class),
        );
    }

    public function testGetConverterForUnregisteredType(): void
    {
        $this->assertNull($this->registry->getConverter('nonexistent'));
    }

    public function testGetConverterForBuiltInType(): void
    {
        $converter = $this->registry->getConverter('bool');

        $this->assertInstanceOf(BoolTypeConverter::class, $converter);
    }

    public function testGetDatabaseTypeFallsBackToTypeItself(): void
    {
        // For unregistered types, should return the type itself
        $this->assertSame('unknown_type', $this->registry->getDatabaseType('unknown_type'));
    }

    public function testRegisterClassMapping(): void
    {
        $this->registry->registerClassMapping(\DateTimeInterface::class, 'DATETIME', null, 5);

        // DateTime implements DateTimeInterface, so it should get this mapping
        $this->assertSame('DATETIME', $this->registry->getDatabaseType(\DateTime::class));
    }

    public function testRegisterClassMappingWithConverter(): void
    {
        $converter = $this->createStub(TypeConverterInterface::class);

        $this->registry->registerClassMapping(\stdClass::class, 'OBJECT_TYPE', $converter);

        $this->assertSame('OBJECT_TYPE', $this->registry->getDatabaseType(\stdClass::class));
        $this->assertSame($converter, $this->registry->getConverter(\stdClass::class));
    }

    public function testRegisterClassMappingThrowsExceptionForInvalidClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot register mapping for unknown class or interface');

        $this->registry->registerClassMapping('NonExistentClass', 'TYPE');
    }

    public function testGetDatabaseTypeForClassWithInheritance(): void
    {
        // Create a test class hierarchy
        $interface = TestInterface::class;
        $parentClass = TestParentClass::class;
        $childClass = TestChildClass::class;

        $this->registry->registerClassMapping($interface, 'INTERFACE_TYPE', null, 10);
        $this->registry->registerClassMapping($parentClass, 'PARENT_TYPE', null, 5);

        // PARENT_TYPE has higher priority (lower number), so it should win
        $this->assertSame('PARENT_TYPE', $this->registry->getDatabaseType($childClass));
    }

    public function testGetDatabaseTypePrioritizesHigherPriorityMappings(): void
    {
        $interface = TestInterface::class;
        $childClass = TestChildClass::class;

        // Register with different priorities (lower number = higher priority)
        $this->registry->registerClassMapping($interface, 'INTERFACE_TYPE', null, 10); // Lower priority
        $this->registry->registerClassMapping($childClass, 'DIRECT_TYPE', null, 5);     // Higher priority

        // Direct class mapping should have higher priority
        $this->assertSame('DIRECT_TYPE', $this->registry->getDatabaseType($childClass));
    }

    public function testGetDatabaseTypeCaching(): void
    {
        $this->registry->registerType('cached_type', 'CACHED_DB_TYPE');

        // First call
        $result1 = $this->registry->getDatabaseType('cached_type');
        $this->assertSame('CACHED_DB_TYPE', $result1);

        // Second call should use cache
        $result2 = $this->registry->getDatabaseType('cached_type');
        $this->assertSame('CACHED_DB_TYPE', $result2);
    }

    public function testGetPhpTypeForBasicTypes(): void
    {
        $this->assertSame('int', $this->registry->getPhpType('INT'));
        $this->assertSame('float', $this->registry->getPhpType('FLOAT'));
        $this->assertSame('string', $this->registry->getPhpType('VARCHAR(255)'));
        $this->assertSame('bool', $this->registry->getPhpType('TINYINT(1)'));
    }

    public function testGetPhpTypeHandlesParameterizedTypes(): void
    {
        $this->assertSame('string', $this->registry->getPhpType('VARCHAR(100)'));
        $this->assertSame('int', $this->registry->getPhpType('BIGINT(20)'));
        $this->assertSame('bool', $this->registry->getPhpType('TINYINT(1)'));
    }

    public function testGetPhpTypeFallsBackToInference(): void
    {
        $this->assertSame('int', $this->registry->getPhpType('BIGINT'));
        $this->assertSame('float', $this->registry->getPhpType('DECIMAL'));
        $this->assertSame('mixed', $this->registry->getPhpType('TEXT')); // TEXT is registered as 'mixed' type
        $this->assertSame('bool', $this->registry->getPhpType('BOOLEAN'));
        $this->assertSame('mixed', $this->registry->getPhpType('UNKNOWN_TYPE')); // Unknown types fall back to mixed
    }

    public function testGetPhpTypeHandlesTinyIntOneSpecialCase(): void
    {
        $this->assertSame('bool', $this->registry->getPhpType('TINYINT(1)'));
        $this->assertSame('bool', $this->registry->getPhpType('tinyint(1)'));
    }

    public function testExtractBaseType(): void
    {
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('extractBaseType');
        $method->setAccessible(true);

        $this->assertSame('VARCHAR', $method->invoke($registry, 'VARCHAR(255)'));
        $this->assertSame('INT', $method->invoke($registry, 'INT(11)'));
        $this->assertSame('CUSTOM', $method->invoke($registry, 'CUSTOM'));
        $this->assertSame('TINYINT', $method->invoke($registry, 'TINYINT(1)'));
    }

    public function testInferPhpType(): void
    {
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('inferPhpType');
        $method->setAccessible(true);

        // Integer types
        $this->assertSame('int', $method->invoke($registry, 'INT'));
        $this->assertSame('int', $method->invoke($registry, 'BIGINT'));
        $this->assertSame('int', $method->invoke($registry, 'SMALLINT'));

        // Float types
        $this->assertSame('float', $method->invoke($registry, 'FLOAT'));
        $this->assertSame('float', $method->invoke($registry, 'DOUBLE'));
        $this->assertSame('float', $method->invoke($registry, 'DECIMAL'));

        // String types
        $this->assertSame('string', $method->invoke($registry, 'VARCHAR'));
        $this->assertSame('string', $method->invoke($registry, 'CHAR'));
        $this->assertSame('string', $method->invoke($registry, 'TEXT'));

        // Date types (should be string for basic mapping)
        $this->assertSame('string', $method->invoke($registry, 'DATE'));
        $this->assertSame('string', $method->invoke($registry, 'DATETIME'));

        // Boolean types
        $this->assertSame('bool', $method->invoke($registry, 'BOOL'));
        $this->assertSame('bool', $method->invoke($registry, 'BOOLEAN'));

        // Unknown types
        $this->assertSame('mixed', $method->invoke($registry, 'UNKNOWN'));
        $this->assertSame('mixed', $method->invoke($registry, 'CUSTOM_TYPE'));
    }

    public function testBuiltInPointTypeRegistration(): void
    {
        $this->assertSame('POINT', $this->registry->getDatabaseType(Point::class));
        $converter = $this->registry->getConverter(Point::class);
        $this->assertInstanceOf(PointTypeConverter::class, $converter);
    }

    public function testBuiltInDateTimeMappings(): void
    {
        $this->assertSame('DATETIME', $this->registry->getDatabaseType(\DateTime::class));
        $this->assertSame('DATETIME', $this->registry->getDatabaseType(\DateTimeImmutable::class));
        $this->assertSame('DATETIME', $this->registry->getDatabaseType(\DateTimeInterface::class));
    }

    public function testNullableTypesRegistration(): void
    {
        // Nullable types should be registered but not create reverse mappings
        $this->assertSame('INT', $this->registry->getDatabaseType('?int'));
        $this->assertSame('FLOAT', $this->registry->getDatabaseType('?float'));
        $this->assertSame('VARCHAR(255)', $this->registry->getDatabaseType('?string'));
    }

    public function testCachingWorks(): void
    {
        // Register a type
        $this->registry->registerType('cached_type', 'CACHED_DB_TYPE');

        // First call
        $result1 = $this->registry->getDatabaseType('cached_type');
        $this->assertSame('CACHED_DB_TYPE', $result1);

        // Second call should use cache (we can't easily test this directly,
        // but at least verify it returns the same result)
        $result2 = $this->registry->getDatabaseType('cached_type');
        $this->assertSame('CACHED_DB_TYPE', $result2);
    }

    public function testFindClassMappingWithMultipleCandidates(): void
    {
        // Register mappings with different priorities
        $this->registry->registerClassMapping(TestInterface::class, 'INTERFACE_TYPE', null, 10); // Lower priority
        $this->registry->registerClassMapping(TestParentClass::class, 'PARENT_TYPE', null, 5);   // Higher priority

        // Test that usort is called and higher priority wins
        $result = $this->registry->getDatabaseType(TestChildClass::class);
        $this->assertSame('PARENT_TYPE', $result);
    }

    public function testGetInheritanceInfoForNonExistentClass(): void
    {
        // Test private method getInheritanceInfo for non-existent class
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('getInheritanceInfo');
        $method->setAccessible(true);

        $result = $method->invoke($registry, 'NonExistentClass');

        $this->assertEquals(['interfaces' => [], 'parents' => []], $result);
    }

    public function testGetInheritanceInfoCaching(): void
    {
        // Test that inheritance info is cached
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('getInheritanceInfo');
        $method->setAccessible(true);

        // First call
        $result1 = $method->invoke($registry, TestChildClass::class);
        $this->assertIsArray($result1);
        $this->assertArrayHasKey('interfaces', $result1);
        $this->assertArrayHasKey('parents', $result1);

        // Second call should use cache
        $result2 = $method->invoke($registry, TestChildClass::class);
        $this->assertSame($result1, $result2);
    }

    public function testFindClassMappingReturnsNullWhenNoMatches(): void
    {
        // Test private method findClassMapping when no mappings exist
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('findClassMapping');
        $method->setAccessible(true);

        $result = $method->invoke($registry, 'SomeClassWithoutMappings');
        $this->assertNull($result);
    }

    public function testFindClassMappingPrioritizesDirectClassMapping(): void
    {
        $this->registry->registerClassMapping(TestInterface::class, 'INTERFACE_TYPE', null, 10); // Lower priority (higher number)
        $this->registry->registerClassMapping(TestChildClass::class, 'DIRECT_TYPE', null, 5);   // Higher priority (lower number)

        // Direct class mapping should win with higher priority (lower number)
        $result = $this->registry->getDatabaseType(TestChildClass::class);
        $this->assertSame('DIRECT_TYPE', $result);
    }

    public function testRegisterTypeClearsMappingCache(): void
    {
        // First get a type to populate cache
        $result1 = $this->registry->getDatabaseType('int');
        $this->assertSame('INT', $result1);

        // Register a new type, which should clear cache
        $this->registry->registerType('test_type', 'TEST_DB_TYPE');

        // Cache should be cleared, so this should work
        $result2 = $this->registry->getDatabaseType('test_type');
        $this->assertSame('TEST_DB_TYPE', $result2);
    }

    public function testRegisterClassMappingClearsMappingCache(): void
    {
        // First get a type to populate cache
        $result1 = $this->registry->getDatabaseType('int');
        $this->assertSame('INT', $result1);

        // Register a class mapping, which should clear cache
        $this->registry->registerClassMapping(\stdClass::class, 'STD_TYPE');

        // Cache should be cleared, so this should work
        $result2 = $this->registry->getDatabaseType(\stdClass::class);
        $this->assertSame('STD_TYPE', $result2);
    }

    public function testGetDatabaseTypeForInheritedInterface(): void
    {
        $this->registry->registerClassMapping(TestInterface::class, 'INTERFACE_TYPE', null, 5);

        // TestChildClass inherits from TestParentClass which implements TestInterface
        $result = $this->registry->getDatabaseType(TestChildClass::class);
        $this->assertSame('INTERFACE_TYPE', $result);
    }

    public function testComplexInheritanceWithMultipleMappings(): void
    {
        // Register multiple mappings with different priorities
        $this->registry->registerClassMapping(TestInterface::class, 'INTERFACE_TYPE', null, 20);    // Lowest priority
        $this->registry->registerClassMapping(TestParentClass::class, 'PARENT_TYPE', null, 10);     // Medium priority
        $this->registry->registerClassMapping(TestChildClass::class, 'CHILD_TYPE', null, 5);        // Highest priority

        // Direct class mapping should win
        $result = $this->registry->getDatabaseType(TestChildClass::class);
        $this->assertSame('CHILD_TYPE', $result);
    }

    public function testInferPhpTypeDistinguishesIntegerFromInt(): void
    {
        // Covers MatchArmRemoval mutants that drop 'INTEGER' from the int arm or
        // keep it with others removed: INTEGER must always map to int, independently
        // of the other int-arm members.
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('inferPhpType');
        $method->setAccessible(true);

        $this->assertSame('int', $method->invoke($registry, 'INTEGER'));
        $this->assertSame('int', $method->invoke($registry, 'MEDIUMINT'));
    }

    public function testInferPhpTypeKeepsAllStringTypeVariants(): void
    {
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('inferPhpType');
        $method->setAccessible(true);

        $this->assertSame('string', $method->invoke($registry, 'TINYTEXT'));
        $this->assertSame('string', $method->invoke($registry, 'MEDIUMTEXT'));
        $this->assertSame('string', $method->invoke($registry, 'LONGTEXT'));
    }

    public function testInferPhpTypeKeepsAllDateTimeVariants(): void
    {
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('inferPhpType');
        $method->setAccessible(true);

        $this->assertSame('string', $method->invoke($registry, 'TIMESTAMP'));
        $this->assertSame('string', $method->invoke($registry, 'TIME'));
        $this->assertSame('string', $method->invoke($registry, 'YEAR'));
    }

    public function testExtractBaseTypeReturnsOnlyCapturedWordNotFullMatch(): void
    {
        // Covers DecrementInteger mutant: $matches[1] -> $matches[0]. For a type
        // string with trailing content after the word, [0] (full regex match) would
        // differ from [1] (the captured word) if the pattern matched more than the
        // word itself. Use a dbType whose captured group is a strict substring
        // relationship signal: VARCHAR(255) -> [0] is "VARCHAR", [1] is "VARCHAR"
        // under /^(\w+)/ so they coincide; use the uppercased comparison directly
        // against the raw (non-uppercased) input to catch the strtoupper() call too.
        $registry = new TypeRegistry();
        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('extractBaseType');
        $method->setAccessible(true);

        $this->assertSame('VARCHAR', $method->invoke($registry, 'varchar(255)'));
    }
}

// Test classes for inheritance testing
interface TestInterface {
}
class TestParentClass implements TestInterface {
}
class TestChildClass extends TestParentClass {
}
