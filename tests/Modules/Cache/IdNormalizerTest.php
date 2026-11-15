<?php

namespace Articulate\Tests\Modules\Cache;

use Articulate\Modules\Cache\IdNormalizer;
use PHPUnit\Framework\TestCase;

class IdNormalizerTest extends TestCase {
    public function testIntIdIsTypeTagged(): void
    {
        $this->assertSame('i:1', IdNormalizer::normalize(1));
    }

    public function testStringIdIsTypeTagged(): void
    {
        $this->assertSame('s:1', IdNormalizer::normalize('1'));
    }

    public function testIntAndStringIdsDoNotCollide(): void
    {
        $this->assertNotSame(IdNormalizer::normalize(1), IdNormalizer::normalize('1'));
    }

    public function testBoolIdIsTypeTagged(): void
    {
        $this->assertSame('b:1', IdNormalizer::normalize(true));
        $this->assertSame('b:0', IdNormalizer::normalize(false));
    }

    public function testFloatIdIsTypeTagged(): void
    {
        $this->assertSame('d:1.5', IdNormalizer::normalize(1.5));
    }

    public function testStringableIdIsSupported(): void
    {
        $id = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'abc-123';
            }
        };

        $this->assertSame(
            IdNormalizer::normalize($id),
            IdNormalizer::normalize($id),
        );
    }

    /**
     * Pins the exact Stringable format (o:ClassName:stringValue) via a
     * golden value — testStringableIdIsSupported above only proves the
     * same call is deterministic, which can't catch a mutant that drops
     * the trailing ':' . $id segment (the normalized string would still be
     * deterministic, just missing the actual string value).
     */
    public function testStringableIdFormatIncludesTheActualStringValue(): void
    {
        $id = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'abc-123';
            }
        };

        $this->assertSame('o:' . $id::class . ':abc-123', IdNormalizer::normalize($id));
    }

    public function testCompositeKeyOrderIsStable(): void
    {
        $this->assertSame(
            IdNormalizer::normalize(['tenant' => 4, 'id' => 7]),
            IdNormalizer::normalize(['id' => 7, 'tenant' => 4]),
        );
    }

    /**
     * Pins the exact per-pair concatenation format (key . '=' . normalize(value))
     * independently of normalize()'s own implementation — a relative-difference
     * assertion alone (testCompositeKeyOrderIsStable) can't catch a mutant that
     * reorders the key/value within one pair, since two differing composite
     * inputs still produce two differing strings under that mutant.
     */
    public function testCompositeKeyFormatMatchesTheDocumentedShapeExactly(): void
    {
        $this->assertSame('a:[id=i:7,tenant=i:4]', IdNormalizer::normalize(['tenant' => 4, 'id' => 7]));
    }

    public function testNonStringableObjectIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdNormalizer::normalize(new \stdClass());
    }
}
