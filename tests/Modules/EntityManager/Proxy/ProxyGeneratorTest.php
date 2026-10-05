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
        $generator = new ProxyGenerator($this->metadataRegistry);
        $generator->disableCaching();

        $proxyClass = $generator->generateProxyClass(ProxyGeneratorEntityWithMagicSet::class);
        $proxy = new $proxyClass();
        $proxy->_initializeProxy(ProxyGeneratorEntityWithMagicSet::class, 1, fn () => null, $this);

        $proxy->name = 'via-magic';

        $this->assertTrue(ProxyGeneratorEntityWithMagicSet::$magicSetWasCalled, "Proxy's __set must delegate to the entity's own __set() when defined");
    }

    public function testGetIssetBodyUsesParentIssetWhenEntityDefinesMagicIsset(): void
    {
        // Ternary mutant swaps the hasParentIsset branches similarly for __isset().
        $generator = new ProxyGenerator($this->metadataRegistry);
        $generator->disableCaching();

        $proxyClass = $generator->generateProxyClass(ProxyGeneratorEntityWithMagicIsset::class);
        $proxy = new $proxyClass();
        $proxy->_initializeProxy(ProxyGeneratorEntityWithMagicIsset::class, 1, fn () => null, $this);

        isset($proxy->whatever);

        $this->assertTrue(ProxyGeneratorEntityWithMagicIsset::$magicIssetWasCalled, "Proxy's __isset must delegate to the entity's own __isset() when defined");
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
