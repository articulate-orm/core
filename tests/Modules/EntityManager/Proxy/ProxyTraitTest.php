<?php

namespace Articulate\Tests\Modules\EntityManager\Proxy;

use Articulate\Modules\EntityManager\Proxy\ProxyTrait;
use PHPUnit\Framework\TestCase;

class ProxyTraitTestEntity {
    use ProxyTrait;

    public ?int $id = null;
}

class ProxyTraitBaseEntity {
    protected function baseMethod(): string
    {
        return 'base';
    }
}

class ProxyTraitCallTestEntity extends ProxyTraitBaseEntity {
    use ProxyTrait;

    public ?int $id = null;
}

class ProxyTraitTest extends TestCase {
    public function testProxyInitialization(): void
    {
        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 123, null, null);

        $this->assertFalse($entity->isProxyInitialized());
        $this->assertEquals(ProxyTraitTestEntity::class, $entity->getProxyEntityClass());
        $this->assertEquals(123, $entity->getProxyIdentifier());
    }

    public function testLazyInitialization(): void
    {
        $initialized = false;
        $initializer = function ($proxy) use (&$initialized) {
            $initialized = true;
        };

        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 123, $initializer, null);

        // Access property to trigger initialization
        $entity->name;

        $this->assertTrue($initialized);
        $this->assertTrue($entity->isProxyInitialized());
    }

    public function testGetTriggersInitialization(): void
    {
        $initialized = false;
        $initializer = function ($proxy) use (&$initialized) {
            $initialized = true;
        };

        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 123, $initializer, null);

        $this->assertFalse($initialized);
        $_ = $entity->name;
        $this->assertTrue($initialized);
    }

    public function testSetTriggersInitialization(): void
    {
        $initialized = false;
        $initializer = function ($proxy) use (&$initialized) {
            $initialized = true;
        };

        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 123, $initializer, null);

        $this->assertFalse($initialized);
        $entity->name = 'test';
        $this->assertTrue($initialized);
    }

    public function testIssetTriggersInitialization(): void
    {
        $initialized = false;
        $initializer = function ($proxy) use (&$initialized) {
            $initialized = true;
        };

        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 123, $initializer, null);

        $this->assertFalse($initialized);
        isset($entity->name);
        $this->assertTrue($initialized);
    }

    public function testUnsetTriggersInitialization(): void
    {
        $initialized = false;
        $initializer = function ($proxy) use (&$initialized) {
            $initialized = true;
        };

        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 123, $initializer, null);

        $this->assertFalse($initialized);
        unset($entity->name);
        $this->assertTrue($initialized);
    }

    public function testCallThrowsForNonExistentMethod(): void
    {
        $entity = new ProxyTraitCallTestEntity();
        $entity->_initializeProxy(ProxyTraitCallTestEntity::class, 1, null, null);

        $this->expectException(\BadMethodCallException::class);
        $entity->nonExistentMethod();
    }

    // ── Mutation killers for 237-241 ─────────────────────────────────────────

    public function testMarkProxyInitializedSetsFlagTrue(): void
    {
        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 1, null, null);

        $this->assertFalse($entity->isProxyInitialized());

        $entity->markProxyInitialized();

        // TrueValue mutant flips this to `false`, so initializeProxy() would
        // re-run the initializer on every subsequent access.
        $this->assertTrue($entity->isProxyInitialized());
    }

    public function testCallInitializesProxyBeforeDelegating(): void
    {
        $initialized = false;
        $initializer = function ($proxy) use (&$initialized) {
            $initialized = true;
        };

        $entity = new ProxyTraitCallTestEntity();
        $entity->_initializeProxy(ProxyTraitCallTestEntity::class, 1, $initializer, null);

        // MethodCallRemoval mutant drops initializeProxy() from __call(), so a
        // parent method call would bypass lazy loading entirely.
        $entity->baseMethod();

        $this->assertTrue($initialized, '__call() must trigger initializeProxy() before delegating to the parent method');
    }

    public function testCallDelegatesToParentMethodAndReturnsItsValue(): void
    {
        $entity = new ProxyTraitCallTestEntity();
        $entity->_initializeProxy(ProxyTraitCallTestEntity::class, 1, null, null);

        $this->assertSame('base', $entity->baseMethod());
    }

    public function testGetProxyRelationPropertyNamesReturnsDeclaredListWhenPropertyExists(): void
    {
        $entity = new ProxyTraitWithRelationPropertiesEntity();
        $entity->_initializeProxy(ProxyTraitWithRelationPropertiesEntity::class, 1, null, null);

        // Ternary mutant swaps the branches, so a declared non-empty
        // _relationProperties array would be ignored in favor of an empty array.
        $this->assertSame(['foo', 'bar'], $entity->getProxyRelationPropertyNames());
    }

    public function testGetProxyRelationPropertyNamesReturnsEmptyArrayWhenPropertyMissing(): void
    {
        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 1, null, null);

        $this->assertSame([], $entity->getProxyRelationPropertyNames());
    }

    public function testLoadRelationDelegatesOnlyWhenProxyManagerHasLoadRelationMethod(): void
    {
        $entity = new ProxyTraitTestEntity();

        // _proxyManager without a loadRelation() method must fall through to null —
        // the LogicalAnd->LogicalOr mutant would instead call method_exists() on
        // a non-object/null $_proxyManager and crash, or worse, call a
        // non-existent method.
        $managerWithoutMethod = new \stdClass();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 1, null, $managerWithoutMethod);

        $reflection = new \ReflectionClass($entity);
        $method = $reflection->getMethod('_loadRelation');
        $method->setAccessible(true);

        $result = $method->invoke($entity, 'someRelation');

        $this->assertNull($result);
    }

    public function testLoadRelationReturnsNullWhenNoProxyManagerSet(): void
    {
        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 1, null, null);

        $reflection = new \ReflectionClass($entity);
        $method = $reflection->getMethod('_loadRelation');
        $method->setAccessible(true);

        $result = $method->invoke($entity, 'someRelation');

        $this->assertNull($result);
    }

    public function testLoadRelationDelegatesToProxyManagerWhenMethodExists(): void
    {
        $manager = new class() {
            public array $calls = [];

            public function loadRelation($proxy, string $name): string
            {
                $this->calls[] = $name;

                return 'loaded:' . $name;
            }
        };

        $entity = new ProxyTraitTestEntity();
        $entity->_initializeProxy(ProxyTraitTestEntity::class, 1, null, $manager);

        $reflection = new \ReflectionClass($entity);
        $method = $reflection->getMethod('_loadRelation');
        $method->setAccessible(true);

        $result = $method->invoke($entity, 'someRelation');

        $this->assertSame('loaded:someRelation', $result);
        $this->assertSame(['someRelation'], $manager->calls);
    }
}

class ProxyTraitWithRelationPropertiesEntity {
    use ProxyTrait;

    private array $_relationProperties = ['foo', 'bar'];
}
