<?php

namespace Articulate\Tests\Modules\Generators;

use Articulate\Modules\Generators\PrefixedIdGeneratorAdapter;
use PHPUnit\Framework\TestCase;

class PrefixedIdGeneratorAdapterTest extends TestCase {
    public function testGetType(): void
    {
        $adapter = new PrefixedIdGeneratorAdapter();

        $this->assertEquals('prefixed_id', $adapter->getType());
    }

    public function testGenerateWithPrefix(): void
    {
        $adapter = new PrefixedIdGeneratorAdapter();

        $id = $adapter->generate('TestEntity', ['prefix' => 'USR_', 'length' => 6]);

        $this->assertStringStartsWith('USR_', $id);
        $this->assertEquals(10, strlen($id));
    }

    public function testGenerateDefaultPrefix(): void
    {
        $adapter = new PrefixedIdGeneratorAdapter();

        $id = $adapter->generate('TestEntity');

        $this->assertIsString($id);
        $this->assertEquals(8, strlen($id));
    }

    public function testGeneratedCharactersAreAlwaysWithinTheKnownAlphabetAcrossManySamples(): void
    {
        // Covers IncrementInteger/Minus mutants widening the random_int() upper
        // bound past the last valid string index: an out-of-range index on a
        // string in PHP emits a warning and yields an empty string for that
        // position, which would show up as a character outside our known set
        // (or a shorter-than-expected string) over enough samples.
        $adapter = new PrefixedIdGeneratorAdapter();
        $allowed = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        for ($i = 0; $i < 500; $i++) {
            $id = $adapter->generate('TestEntity', ['length' => 32]);
            $this->assertSame(32, strlen($id), "Generated id had unexpected length on iteration {$i}");

            foreach (str_split($id) as $char) {
                $this->assertStringContainsString($char, $allowed, "Unexpected character '{$char}' outside known alphabet");
            }
        }
    }

    public function testGeneratedCharactersEventuallyReachTheLastAlphabetCharacter(): void
    {
        // Covers DecrementInteger mutant shrinking the upper bound
        // (strlen($characters) - 1 -> - 2), which would make the final
        // character ('Z') unreachable. Over a large enough sample, 'Z' must
        // appear at least once.
        $adapter = new PrefixedIdGeneratorAdapter();

        $seenZ = false;
        for ($i = 0; $i < 2000 && !$seenZ; $i++) {
            $id = $adapter->generate('TestEntity', ['length' => 16]);
            if (str_contains($id, 'Z')) {
                $seenZ = true;
            }
        }

        $this->assertTrue($seenZ, "Expected 'Z' to appear at least once across 2000 samples of length-16 ids");
    }
}
