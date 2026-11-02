<?php

namespace Articulate\Tests\Utils;

use Articulate\Utils\Point;
use PHPUnit\Framework\TestCase;

class PointTest extends TestCase {
    public function testConstructsWithCoordinates(): void
    {
        $point = new Point(10.5, -20.3);

        $this->assertSame(10.5, $point->x);
        $this->assertSame(-20.3, $point->y);
    }

    public function testToStringReturnsCorrectFormat(): void
    {
        $point = new Point(12.34, 56.78);
        $expected = 'POINT(12.340000 56.780000)';

        $this->assertSame($expected, $point->toString());
    }

    public function testToStringMethod(): void
    {
        $point = new Point(1.0, 2.0);

        $this->assertSame('POINT(1.000000 2.000000)', $point->toString());
    }

    public function testMagicToStringMethod(): void
    {
        $point = new Point(3.14, 2.71);

        $this->assertSame('POINT(3.140000 2.710000)', (string) $point);
    }

    public function testFromStringWithValidFormat(): void
    {
        $pointString = 'POINT(10.5 -20.3)';
        $point = Point::fromString($pointString);

        $this->assertSame(10.5, $point->x);
        $this->assertSame(-20.3, $point->y);
    }

    public function testFromStringWithDifferentCoordinates(): void
    {
        $testCases = [
            ['POINT(0.0 0.0)', 0.0, 0.0],
            ['POINT(123.456 789.012)', 123.456, 789.012],
            ['POINT(-45.67 89.01)', -45.67, 89.01],
            ['POINT(1 2)', 1.0, 2.0], // Integers should become floats
        ];

        foreach ($testCases as [$pointString, $expectedX, $expectedY]) {
            $point = Point::fromString($pointString);
            $this->assertSame($expectedX, $point->x, "Failed for input: {$pointString}");
            $this->assertSame($expectedY, $point->y, "Failed for input: {$pointString}");
        }
    }

    public function testFromStringThrowsExceptionForInvalidFormat(): void
    {
        $invalidFormats = [
            'INVALID(10.5 20.3)',
            'POINT(10.5)', // Missing Y coordinate
            'POINT(10.5 20.3 30.4)', // Too many coordinates
            'POINT()', // Empty
            'POINT(abc def)', // Non-numeric
            'POINT 10.5 20.3', // Missing parentheses
            '(10.5 20.3)', // Missing POINT prefix
            'POINT(10.5, 20.3)', // Wrong separator
        ];

        foreach ($invalidFormats as $invalidFormat) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage("Invalid POINT format: {$invalidFormat}");
            Point::fromString($invalidFormat);
        }
    }

    public function testRoundTripConversion(): void
    {
        $originalX = 15.75;
        $originalY = -42.123;

        $point = new Point($originalX, $originalY);
        $pointString = $point->toString();
        $parsedPoint = Point::fromString($pointString);

        $this->assertSame($originalX, $parsedPoint->x);
        $this->assertSame($originalY, $parsedPoint->y);
    }

    public function testPropertiesAreAccessible(): void
    {
        $point = new Point(1.0, 2.0);

        $this->assertSame(1.0, $point->x);
        $this->assertSame(2.0, $point->y);
    }

    public function testFloatingPointPrecision(): void
    {
        // Test with high precision values
        $point = new Point(1.23456789, -9.87654321);
        $pointString = $point->toString();

        // Parse it back
        $parsed = Point::fromString($pointString);

        // Should maintain reasonable precision (up to 6 decimal places in string representation)
        $this->assertEqualsWithDelta(1.234568, $parsed->x, 0.000001);
        $this->assertEqualsWithDelta(-9.876543, $parsed->y, 0.000001);
    }

    public function testScientificNotationCoordinates(): void
    {
        // Test with scientific notation (should work since float conversion handles it)
        $point = Point::fromString('POINT(1.23e2 4.56e-3)');

        $this->assertSame(123.0, $point->x);
        $this->assertSame(0.00456, $point->y);
    }

    public function testFromStringRejectsTrailingContentAfterClosingParen(): void
    {
        // Covers PregMatchRemoveDollar: without the trailing $ anchor, trailing
        // garbage after the closing paren would still match.
        $this->expectException(\InvalidArgumentException::class);
        Point::fromString('POINT(1.0 2.0) garbage');
    }

    public function testFromStringRejectsLeadingContentBeforePoint(): void
    {
        // Covers PregMatchRemoveCaret: without the leading ^ anchor, leading
        // garbage before POINT( would still match.
        $this->expectException(\InvalidArgumentException::class);
        Point::fromString('garbage POINT(1.0 2.0)');
    }

    public function testFromStringCastsBothCoordinatesToFloatIndependently(): void
    {
        // Covers CastFloat mutants on $matches[1]/$matches[2] individually: if only
        // one side were cast, passing numeric strings would still produce a Point
        // whose x/y are not strict floats on the affected side.
        $point = Point::fromString('POINT(7 9)');

        $this->assertIsFloat($point->x);
        $this->assertIsFloat($point->y);
        $this->assertSame(7.0, $point->x);
        $this->assertSame(9.0, $point->y);
    }
}
