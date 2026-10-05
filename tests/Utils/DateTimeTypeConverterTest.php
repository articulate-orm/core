<?php

namespace Articulate\Tests\Utils;

use Articulate\Utils\DateTimeTypeConverter;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DateTimeTypeConverterTest extends TestCase {
    private DateTimeTypeConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DateTimeTypeConverter();
    }

    public function testConvertToPHP(): void
    {
        $result = $this->converter->convertToPHP('2024-01-15 10:30:00');

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertSame('2024-01-15 10:30:00', $result->format('Y-m-d H:i:s'));
    }

    public function testConvertToPHPAsImmutable(): void
    {
        $result = $this->converter->convertToPHP('2024-06-01 08:00:00', DateTimeImmutable::class);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2024-06-01 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testConvertToDatabase(): void
    {
        $dateTime = new DateTime('2024-01-15 10:30:00');

        $this->assertSame('2024-01-15 10:30:00', $this->converter->convertToDatabase($dateTime));
    }

    public function testConvertImmutableToDatabase(): void
    {
        $dateTime = new DateTimeImmutable('2024-03-20 14:45:00');

        $this->assertSame('2024-03-20 14:45:00', $this->converter->convertToDatabase($dateTime));
    }

    public function testConvertNullToPHP(): void
    {
        $this->assertNull($this->converter->convertToPHP(null));
    }

    public function testConvertNullToDatabase(): void
    {
        $this->assertNull($this->converter->convertToDatabase(null));
    }

    public function testConvertToDatabasePassesThroughNonDateTimeValue(): void
    {
        // Covers LogicalOrAllSubExprNegation: the instanceof check must use OR
        // so either DateTime or DateTimeImmutable is formatted; a plain string
        // (neither) must pass through unchanged, proving the check isn't an
        // always-true/always-false collapse.
        $this->assertSame('already-a-string', $this->converter->convertToDatabase('already-a-string'));
    }

    public function testConvertImmutableWithNullTargetTypeReturnsSameInstance(): void
    {
        // Covers ReturnRemoval mutant on "return $value;" inside the
        // DateTime/DateTimeImmutable branch when no conversion is requested.
        $dateTime = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->converter->convertToPHP($dateTime);

        $this->assertSame($dateTime, $result);
    }
}
