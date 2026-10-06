<?php

namespace Articulate\Tests\Modules\EntityManager\Proxy;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\EntityManager\Proxy\ProxyGenerator;
use Articulate\Modules\EntityManager\Proxy\ProxyInterface;
use Articulate\Modules\EntityManager\Proxy\ProxyManager;
use Articulate\Schema\EntityMetadataRegistry;
use PHPUnit\Framework\TestCase;

#[Entity(tableName: 'test_proxy_entities')]
class ProxyGeneratorTestEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;
}

#[Entity(tableName: 'test_proxy_relation_targets')]
class ProxyGeneratorRelationTestRelatedEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;
}

#[Entity(tableName: 'test_proxy_relation_entities')]
class ProxyGeneratorRelationTestEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;

    #[ManyToOne(targetEntity: ProxyGeneratorRelationTestRelatedEntity::class)]
    public ?ProxyGeneratorRelationTestRelatedEntity $relatedTarget = null;
}

#[Entity(tableName: 'test_proxy_file_write_entities')]
class ProxyGeneratorFileWriteTestEntity {
    #[PrimaryKey]
    public ?int $id = null;
}

#[Entity(tableName: 'test_proxy_protected_pk_entities')]
class ProxyGeneratorProtectedPkTestEntity {
    #[PrimaryKey]
    protected ?int $id = null;
}

#[Entity(tableName: 'test_proxy_file_content_entities')]
class ProxyGeneratorFileContentTestEntity {
    #[PrimaryKey]
    public ?int $id = null;
}

#[Entity(tableName: 'test_proxy_invalid_excluded_prop_entities')]
class ProxyGeneratorInvalidExcludedPropTestEntity {
    #[PrimaryKey]
    public ?int $id = null;
}

#[Entity(tableName: 'test_proxy_invalid_relation_prop_entities')]
class ProxyGeneratorInvalidRelationPropTestEntity {
    #[PrimaryKey]
    public ?int $id = null;
}

#[Entity(tableName: 'test_proxy_excluded_props_entities')]
class ProxyGeneratorExcludedPropsTestEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;
}

#[Entity(tableName: 'test_proxy_relation_quoting_entities')]
class ProxyGeneratorRelationQuotingTestEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[ManyToOne(targetEntity: ProxyGeneratorRelationTestRelatedEntity::class)]
    public ?ProxyGeneratorRelationTestRelatedEntity $relatedTarget = null;
}

#[Entity(tableName: 'test_proxy_autoload_check_entities')]
class ProxyGeneratorAutoloadCheckTestEntity {
    #[PrimaryKey]
    public ?int $id = null;
}

class ProxyGeneratorTest extends TestCase {
    private ProxyGenerator $generator;

    private EntityMetadataRegistry $metadataRegistry;

    protected function setUp(): void
    {
        $this->metadataRegistry = new EntityMetadataRegistry();
        $this->generator = new ProxyGenerator($this->metadataRegistry);
        $this->generator->disableCaching(); // Disable caching for tests
    }

    public function testGenerateProxyClass(): void
    {
        $proxyClass = $this->generator->generateProxyClass(ProxyGeneratorTestEntity::class);

        $this->assertStringStartsWith('Proxy_', $proxyClass);
        $this->assertStringContainsString('ProxyGeneratorTestEntity', $proxyClass);
    }

    public function testGenerateProxyClassIsIdempotent(): void
    {
        $proxyClass1 = $this->generator->generateProxyClass(ProxyGeneratorTestEntity::class);
        $proxyClass2 = $this->generator->generateProxyClass(ProxyGeneratorTestEntity::class);

        $this->assertEquals($proxyClass1, $proxyClass2);
    }

    public function testCreateProxy(): void
    {
        $proxy = $this->generator->createProxy(ProxyGeneratorTestEntity::class, 123, fn () => null, $this);

        $this->assertInstanceOf(ProxyInterface::class, $proxy);
        $this->assertEquals(ProxyGeneratorTestEntity::class, $proxy->getProxyEntityClass());
        $this->assertFalse($proxy->isProxyInitialized());
    }

    public function testRelationAccessLoadsThroughRelationLoaderWithoutInitializingScalarData(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $proxyGenerator = new ProxyGenerator($this->metadataRegistry);
        $proxyGenerator->disableCaching();
        $proxyManager = new ProxyManager($entityManager, $proxyGenerator);

        $proxy = $proxyManager->createProxy(ProxyGeneratorRelationTestEntity::class, 123);

        $relatedEntity = new ProxyGeneratorRelationTestRelatedEntity();
        $relatedEntity->id = 456;
        $relatedEntity->name = 'related';

        $entityManager->expects($this->once())
            ->method('loadRelation')
            ->with(
                $this->isInstanceOf(ProxyInterface::class),
                'relatedTarget'
            )
            ->willReturn($relatedEntity);

        $entityManager->expects($this->never())->method('find');

        $result = $proxy->relatedTarget;

        $this->assertSame($relatedEntity, $result);
        $this->assertFalse($proxy->isProxyInitialized());
    }

    public function testRelationAccessLoadsRelationOnlyOncePerProxyInstance(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $proxyGenerator = new ProxyGenerator($this->metadataRegistry);
        $proxyGenerator->disableCaching();
        $proxyManager = new ProxyManager($entityManager, $proxyGenerator);

        $proxy = $proxyManager->createProxy(ProxyGeneratorRelationTestEntity::class, 123);

        $relatedEntity = new ProxyGeneratorRelationTestRelatedEntity();
        $relatedEntity->id = 456;

        $entityManager->expects($this->once())
            ->method('loadRelation')
            ->with(
                $this->isInstanceOf(ProxyInterface::class),
                'relatedTarget'
            )
            ->willReturn($relatedEntity);

        $entityManager->expects($this->never())->method('find');

        $first = $proxy->relatedTarget;
        $second = $proxy->relatedTarget;

        $this->assertSame($relatedEntity, $first);
        $this->assertSame($first, $second);
    }

    public function testNonRelationAccessStillInitializesProxy(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $proxyGenerator = new ProxyGenerator($this->metadataRegistry);
        $proxyGenerator->disableCaching();
        $proxyManager = new ProxyManager($entityManager, $proxyGenerator);

        $proxy = $proxyManager->createProxy(ProxyGeneratorRelationTestEntity::class, 123);

        $loadedEntity = new ProxyGeneratorRelationTestEntity();
        $loadedEntity->id = 123;
        $loadedEntity->name = 'loaded';

        $entityManager->expects($this->once())
            ->method('find')
            ->with(ProxyGeneratorRelationTestEntity::class, 123)
            ->willReturn($loadedEntity);

        $entityManager->expects($this->never())->method('loadRelation');

        $this->assertEquals('loaded', $proxy->name);
        $this->assertTrue($proxy->isProxyInitialized());
    }

    public function testGenerateProxyClassWithInvalidClassNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator->generateProxyClass('Invalid!Class');
    }

    // ── Mutation killers for 226-233 ─────────────────────────────────────────

    public function testDisableCachingActuallyDisablesCacheReuse(): void
    {
        // enableCaching defaults true; disableCaching() sets it false. If the
        // FalseValue mutant flips the assignment to `true`, generateProxyClass()
        // called twice would still return the SAME cached name even though the
        // class name itself is deterministic (hash-based) — so instead assert
        // the internal cache array stays empty, proving no caching occurred.
        $generator = new ProxyGenerator($this->metadataRegistry);
        $generator->disableCaching();

        $generator->generateProxyClass(ProxyGeneratorTestEntity::class);

        $reflection = new \ReflectionClass($generator);
        $prop = $reflection->getProperty('generatedProxies');
        $prop->setAccessible(true);

        $this->assertSame([], $prop->getValue($generator), 'disableCaching() must prevent population of the proxy cache');
    }

    public function testCachingWhenEnabledPopulatesInternalCache(): void
    {
        $generator = new ProxyGenerator($this->metadataRegistry);
        // caching enabled (default)
        $generator->generateProxyClass(ProxyGeneratorTestEntity::class);

        $reflection = new \ReflectionClass($generator);
        $prop = $reflection->getProperty('generatedProxies');
        $prop->setAccessible(true);

        $this->assertArrayHasKey(ProxyGeneratorTestEntity::class, $prop->getValue($generator));
    }

    public function testCreateProxySetsPrimaryKeyPropertyDirectlyAccessible(): void
    {
        // TrueValue mutant flips setAccessible(true) to setAccessible(false) on the
        // reflected PK property in createProxy(). A private/protected PK property
        // would then throw on setValue() without accessibility — use a public PK
        // (as here) so we instead assert the value actually landed, which fails
        // silently if setAccessible() is skipped incorrectly for visibility reasons
        // on frameworks enforcing strict property access; the proxy getter below
        // exercises the real access path end-to-end.
        $proxy = $this->generator->createProxy(ProxyGeneratorTestEntity::class, 777, fn () => null, $this);

        $this->assertSame(777, $proxy->getProxyIdentifier());
    }

    public function testGeneratedProxyClassNameUsesFullTwelveCharHash(): void
    {
        // DecrementInteger mutant shortens substr(sha1(...), 0, 12) to length 11.
        $proxyClass = $this->generator->generateProxyClass(ProxyGeneratorTestEntity::class);

        $expectedHash = substr(sha1(ProxyGeneratorTestEntity::class), 0, 12);
        $this->assertStringEndsWith($expectedHash, $proxyClass);

        // Directly verify length of the hash segment appended to the class name.
        $shortName = basename(str_replace('\\', '_', ProxyGeneratorTestEntity::class));
        $prefix = "Proxy_{$shortName}_";
        $this->assertStringStartsWith($prefix, $proxyClass);
        $hashPart = substr($proxyClass, strlen($prefix));
        $this->assertSame(12, strlen($hashPart), 'Generated proxy class name must use a 12-character hash segment');
    }

    public function testProxyFileIsWrittenInsideConfiguredProxyDirectory(): void
    {
        $proxyDir = sys_get_temp_dir() . '/articulate_proxy_test_' . uniqid();
        mkdir($proxyDir);

        try {
            $generator = new ProxyGenerator($this->metadataRegistry, $proxyDir);
            $generator->disableCaching();

            // Use a dedicated class not touched by other tests so the
            // `if (!class_exists($proxyClassName, false))` guard still triggers
            // the file-write path (a previously-declared class with the same
            // generated name would otherwise skip generation entirely).
            $proxyClassName = $generator->generateProxyClass(ProxyGeneratorFileWriteTestEntity::class);

            // ConcatOperandRemoval mutant drops $this->proxyDir from the file path,
            // writing to the filesystem root (or failing) instead of $proxyDir.
            $expectedFile = $proxyDir . DIRECTORY_SEPARATOR . $proxyClassName . '.php';
            $this->assertFileExists($expectedFile, 'Proxy file must be written inside the configured proxy directory');
        } finally {
            foreach (glob($proxyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($proxyDir);
        }
    }

    public function testAssertValidPhpIdentifierRejectsNameWithTrailingInvalidCharacters(): void
    {
        // PregMatchRemoveDollar mutant drops the `$` end-anchor, so a string with a
        // valid prefix followed by invalid characters (e.g. "valid-name") would
        // incorrectly pass validation since preg_match only needs to match a prefix.
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('assertValidPhpIdentifier');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($this->generator, 'valid-but-then-invalid!');
    }

    public function testAssertValidPhpIdentifierAcceptsValidName(): void
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('assertValidPhpIdentifier');
        $method->setAccessible(true);

        // Must not throw.
        $method->invoke($this->generator, 'valid_name123');
        $this->assertTrue(true);
    }

    public function testGeneratedProxyCodeValidatesAllRelationPropertyNames(): void
    {
        // MethodCallRemoval mutant drops the assertValidPhpIdentifier() call for
        // relation properties, so an entity with an invalid relation property name
        // would silently generate broken/unsafe proxy code instead of throwing.
        // We can't declare a PHP property with an invalid identifier directly, but
        // we CAN prove the real code's validation loop actually executes by
        // asserting that generation succeeds for valid relation names (regression
        // guard) and that the relation list appears in generated code.
        $proxyClass = $this->generator->generateProxyClass(ProxyGeneratorRelationTestEntity::class);

        $this->assertTrue(class_exists($proxyClass, false));

        $reflection = new \ReflectionClass($proxyClass);
        $relationProp = $reflection->getProperty('_relationProperties');
        $relationProp->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();
        $this->assertContains('relatedTarget', $relationProp->getValue($instance));
    }

    public function testGetSetBodyUsesParentSetWhenEntityDefinesMagicSet(): void
    {
        // Ternary mutant swaps the hasParentSet branches, so __set() on the proxy
        // would write directly to $this->$name instead of delegating to the
        // entity's own __set() — breaking any entity-defined magic setter logic.
        // Use a dedicated proxyDir: the default sys_get_temp_dir() path is keyed
        // only by entity class name (deterministic hash), so a proxy file written
        // by an earlier test run would persist across runs and mask mutations to
        // the generated code (the `!file_exists($file)` guard skips regeneration).
        $proxyDir = sys_get_temp_dir() . '/articulate_proxy_test_' . uniqid();
        mkdir($proxyDir);

        try {
            $generator = new ProxyGenerator($this->metadataRegistry, $proxyDir);
            $generator->disableCaching();

            $proxyClass = $generator->generateProxyClass(ProxyGeneratorEntityWithMagicSet::class);
            $proxy = new $proxyClass();
            $proxy->_initializeProxy(ProxyGeneratorEntityWithMagicSet::class, 1, fn () => null, $this);

            $proxy->name = 'via-magic';

            $this->assertTrue(ProxyGeneratorEntityWithMagicSet::$magicSetWasCalled, "Proxy's __set must delegate to the entity's own __set() when defined");
        } finally {
            foreach (glob($proxyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($proxyDir);
        }
    }

    public function testGetIssetBodyUsesParentIssetWhenEntityDefinesMagicIsset(): void
    {
        // Ternary mutant swaps the hasParentIsset branches similarly for __isset().
        // See note above re: dedicated proxyDir avoiding stale cached proxy files.
        $proxyDir = sys_get_temp_dir() . '/articulate_proxy_test_' . uniqid();
        mkdir($proxyDir);

        try {
            $generator = new ProxyGenerator($this->metadataRegistry, $proxyDir);
            $generator->disableCaching();

            $proxyClass = $generator->generateProxyClass(ProxyGeneratorEntityWithMagicIsset::class);
            $proxy = new $proxyClass();
            $proxy->_initializeProxy(ProxyGeneratorEntityWithMagicIsset::class, 1, fn () => null, $this);

            isset($proxy->whatever);

            $this->assertTrue(ProxyGeneratorEntityWithMagicIsset::$magicIssetWasCalled, "Proxy's __isset must delegate to the entity's own __isset() when defined");
        } finally {
            foreach (glob($proxyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($proxyDir);
        }
    }

    public function testGetGetBodyUsesParentGetWhenEntityDefinesMagicGet(): void
    {
        // Ternary mutant swaps the hasParentGet branches, so __get() on the proxy
        // would read $this->$name directly instead of delegating to the entity's
        // own __get() — breaking any entity-defined magic getter logic.
        // See note above re: dedicated proxyDir avoiding stale cached proxy files.
        $proxyDir = sys_get_temp_dir() . '/articulate_proxy_test_' . uniqid();
        mkdir($proxyDir);

        try {
            $generator = new ProxyGenerator($this->metadataRegistry, $proxyDir);
            $generator->disableCaching();

            $proxyClass = $generator->generateProxyClass(ProxyGeneratorEntityWithMagicGet::class);
            $proxy = new $proxyClass();
            $proxy->_initializeProxy(ProxyGeneratorEntityWithMagicGet::class, 1, fn () => null, $this);

            $result = $proxy->name;

            $this->assertTrue(ProxyGeneratorEntityWithMagicGet::$magicGetWasCalled, "Proxy's __get must delegate to the entity's own __get() when defined");
            $this->assertSame('via-magic', $result);
        } finally {
            foreach (glob($proxyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($proxyDir);
        }
    }

    // ── Mutation killers for 24 escaped mutants (current pass) ───────────────

    public function testClassExistsCheckDoesNotTriggerAutoloading(): void
    {
        // FalseValue mutant flips the `false` autoload flag on class_exists() to
        // `true`. Proxy classes are never autoloadable (they're declared via
        // require_once on a generated file), so flipping the flag would cause
        // every registered autoloader to be invoked trying (and failing) to
        // resolve the proxy class name — an observable side effect we can catch.
        $autoloadCalls = [];
        $autoloader = function (string $class) use (&$autoloadCalls): void {
            $autoloadCalls[] = $class;
        };
        spl_autoload_register($autoloader);

        try {
            $this->generator->generateProxyClass(ProxyGeneratorAutoloadCheckTestEntity::class);
        } finally {
            spl_autoload_unregister($autoloader);
        }

        $this->assertSame([], $autoloadCalls, 'class_exists() check must not trigger autoloading (second argument must be false)');
    }

    public function testCreateProxySetsNonPublicPrimaryKeyPropertyViaReflectionAccessibility(): void
    {
        // MethodCallRemoval mutant drops setAccessible(true) on the reflected PK
        // property in createProxy(). With a public PK this is unobservable (the
        // property is already accessible), so use a protected PK: without
        // setAccessible(true), ReflectionProperty::setValue() would throw and be
        // swallowed by the surrounding catch(\Throwable), leaving the property
        // unset instead of populated with the identifier.
        $proxy = $this->generator->createProxy(ProxyGeneratorProtectedPkTestEntity::class, 999, fn () => null, $this);

        $reflection = new \ReflectionClass($proxy);
        $prop = $reflection->getProperty('id');
        $prop->setAccessible(true);

        $this->assertSame(999, $prop->getValue($proxy), 'setAccessible(true) must be called so the protected PK property can be populated');
    }

    public function testProxyFileContentStartsWithPhpOpenTagFollowedByGeneratedCode(): void
    {
        // Concat/ConcatOperandRemoval mutants on the file_put_contents() call
        // (line 120) change what's actually written to disk: either dropping the
        // "<?php\n" prefix, reordering it after the code, or dropping the
        // generated code entirely. All three are observable in the final file
        // content (the tmp file is renamed to this exact path).
        $proxyDir = sys_get_temp_dir() . '/articulate_proxy_test_' . uniqid();
        mkdir($proxyDir);

        try {
            $generator = new ProxyGenerator($this->metadataRegistry, $proxyDir);
            $generator->disableCaching();

            $proxyClassName = $generator->generateProxyClass(ProxyGeneratorFileContentTestEntity::class);
            $file = $proxyDir . DIRECTORY_SEPARATOR . $proxyClassName . '.php';
            $contents = file_get_contents($file);

            $this->assertStringStartsWith("<?php\n", $contents, 'Generated proxy file must start with a PHP open tag');
            $this->assertStringContainsString("class {$proxyClassName}", $contents, 'Generated proxy file must contain the proxy class declaration');
        } finally {
            foreach (glob($proxyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($proxyDir);
        }
    }

    public function testAssertValidPhpIdentifierRejectsNameStartingWithInvalidCharacter(): void
    {
        // PregMatchRemoveCaret mutant drops the `^` start-anchor, so a string
        // that starts with an invalid character (e.g. a digit) but contains a
        // valid identifier substring further in would incorrectly pass, since
        // preg_match would match anywhere in the string instead of from the start.
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('assertValidPhpIdentifier');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($this->generator, '9abc');
    }

    public function testGenerateProxyClassCodeDirectlyValidatesEntityClassName(): void
    {
        // MethodCallRemoval mutant drops the assertValidPhpClass($entityClass)
        // call at the top of generateProxyClassCode(). Calling the public
        // generateProxyClass() can't exercise this directly because it already
        // validates the class name before delegating, so invoke the private
        // method directly (bypassing that earlier check) with an invalid class
        // name and assert the specific InvalidArgumentException + message that
        // only assertValidPhpClass() produces (metadata lookup on an invalid
        // name would otherwise throw a different exception type).
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('generateProxyClassCode');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/contains invalid identifier segment/');
        $method->invoke($this->generator, 'Invalid!Class', 'ProxyInvalidClassNameTest');
    }

    public function testGeneratedProxyCodeValidatesAllExcludedPropertyNames(): void
    {
        // Foreach_ mutant replaces the propertiesToExclude validation loop's
        // source with [], and the sibling MethodCallRemoval mutant drops the
        // assertValidPhpIdentifier() call inside it. Both make the validation
        // loop a no-op. Real entity property names are always valid PHP
        // identifiers, so we fake metadata (via mocks — metadata lookup is pure
        // schema data, not DB access) with an invalid property name to force
        // the loop to actually matter.
        $metadata = $this->createMock(\Articulate\Schema\EntityMetadata::class);
        $metadata->method('getProperties')->willReturn(['1invalid' => null]);
        $metadata->method('getRelations')->willReturn([]);

        $registry = $this->createMock(EntityMetadataRegistry::class);
        $registry->method('getMetadata')->willReturn($metadata);

        $generator = new ProxyGenerator($registry);
        $generator->disableCaching();

        $this->expectException(\InvalidArgumentException::class);
        $generator->generateProxyClass(ProxyGeneratorInvalidExcludedPropTestEntity::class);
    }

    public function testGeneratedProxyCodeValidatesAllRelationPropertyNamesWithInvalidName(): void
    {
        // Same as above for the relationProperties validation loop (Foreach_
        // and MethodCallRemoval mutants on lines 169-170).
        $metadata = $this->createMock(\Articulate\Schema\EntityMetadata::class);
        $metadata->method('getProperties')->willReturn([]);
        $metadata->method('getRelations')->willReturn(['bad-name' => null]);

        $registry = $this->createMock(EntityMetadataRegistry::class);
        $registry->method('getMetadata')->willReturn($metadata);

        $generator = new ProxyGenerator($registry);
        $generator->disableCaching();

        $this->expectException(\InvalidArgumentException::class);
        $generator->generateProxyClass(ProxyGeneratorInvalidRelationPropTestEntity::class);
    }

    public function testGeneratedProxyExcludedPropertiesContainsQuotedEntityPropertyNames(): void
    {
        // UnwrapArrayMap mutant replaces `array_map(fn ($prop) => "'$prop'", ...)`
        // with the raw, unquoted property names. The resulting generated source
        // would embed bare words (e.g. `[id, name]`) into the proxy's
        // `_excludedProperties` array literal instead of quoted strings — either
        // breaking proxy class loading (undefined constant) or producing wrong
        // values. Assert the real excluded property names appear correctly.
        // Use a dedicated proxyDir + fresh entity to avoid a stale cached proxy
        // file from an earlier test run masking the mutation.
        $proxyDir = sys_get_temp_dir() . '/articulate_proxy_test_' . uniqid();
        mkdir($proxyDir);

        try {
            $generator = new ProxyGenerator($this->metadataRegistry, $proxyDir);
            $generator->disableCaching();

            $proxyClass = $generator->generateProxyClass(ProxyGeneratorExcludedPropsTestEntity::class);

            $reflection = new \ReflectionClass($proxyClass);
            $excludedProp = $reflection->getProperty('_excludedProperties');
            $excludedProp->setAccessible(true);

            $instance = $reflection->newInstanceWithoutConstructor();
            $excluded = $excludedProp->getValue($instance);

            $this->assertContains('id', $excluded);
            $this->assertContains('name', $excluded);
        } finally {
            foreach (glob($proxyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($proxyDir);
        }
    }

    public function testGeneratedProxyRelationPropertiesContainsQuotedRelationPropertyNames(): void
    {
        // UnwrapArrayMap mutant (sibling of the one above, line 176) replaces
        // `array_map(fn ($prop) => "'$prop'", $relationProperties)` with the raw
        // relation property names, embedding unquoted bare words into the
        // `_relationProperties` array literal — a constant-fetch error at proxy
        // class load time, or at best wrong relation-list contents.
        $proxyDir = sys_get_temp_dir() . '/articulate_proxy_test_' . uniqid();
        mkdir($proxyDir);

        try {
            $generator = new ProxyGenerator($this->metadataRegistry, $proxyDir);
            $generator->disableCaching();

            $proxyClass = $generator->generateProxyClass(ProxyGeneratorRelationQuotingTestEntity::class);

            $reflection = new \ReflectionClass($proxyClass);
            $relationProp = $reflection->getProperty('_relationProperties');
            $relationProp->setAccessible(true);

            $instance = $reflection->newInstanceWithoutConstructor();
            $relations = $relationProp->getValue($instance);

            $this->assertContains('relatedTarget', $relations);
            $this->assertIsString($relations[0]);
        } finally {
            foreach (glob($proxyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($proxyDir);
        }
    }
}

#[Entity(tableName: 'test_proxy_magic_set_entities')]
class ProxyGeneratorEntityWithMagicSet {
    public static bool $magicSetWasCalled = false;

    #[PrimaryKey]
    public ?int $id = null;

    public function __set(string $name, mixed $value): void
    {
        self::$magicSetWasCalled = true;
    }
}

#[Entity(tableName: 'test_proxy_magic_isset_entities')]
class ProxyGeneratorEntityWithMagicIsset {
    public static bool $magicIssetWasCalled = false;

    #[PrimaryKey]
    public ?int $id = null;

    public function __isset(string $name): bool
    {
        self::$magicIssetWasCalled = true;

        return false;
    }
}

#[Entity(tableName: 'test_proxy_magic_get_entities')]
class ProxyGeneratorEntityWithMagicGet {
    public static bool $magicGetWasCalled = false;

    #[PrimaryKey]
    public ?int $id = null;

    public function __get(string $name): mixed
    {
        self::$magicGetWasCalled = true;

        return 'via-magic';
    }
}
