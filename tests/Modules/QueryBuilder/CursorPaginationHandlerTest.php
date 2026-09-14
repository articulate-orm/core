<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Exceptions\CursorPaginationException;
use Articulate\Modules\QueryBuilder\Cursor;
use Articulate\Modules\QueryBuilder\CursorCodec;
use Articulate\Modules\QueryBuilder\CursorDirection;
use Articulate\Modules\QueryBuilder\CursorPaginationHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CursorPaginationHandlerTest extends TestCase {
    private CursorPaginationHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CursorPaginationHandler(new CursorCodec(), null);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function scalarNormalizationProvider(): array
    {
        return [
            'int' => [42, 42],
            'string' => ['abc', 'abc'],
            'float' => [3.5, 3.5],
            'bool' => [true, true],
        ];
    }

    #[DataProvider('scalarNormalizationProvider')]
    public function testScalarValuesPassThroughUnchanged(mixed $input, mixed $expected): void
    {
        $parsed = [['column' => 'val', 'direction' => 'ASC']];
        $values = $this->handler->extractCursorValues(['val' => $input], $parsed, null);

        $this->assertSame([$expected], $values);
    }

    public function testDateTimeIsNormalizedToStoredScalarFormat(): void
    {
        $dt = new \DateTime('2026-01-15 13:45:30');
        $parsed = [['column' => 'created_at', 'direction' => 'ASC']];

        $values = $this->handler->extractCursorValues(['created_at' => $dt], $parsed, null);

        $this->assertSame(['2026-01-15 13:45:30'], $values);
    }

    public function testDateTimeImmutableIsNormalizedToStoredScalarFormat(): void
    {
        $dt = new \DateTimeImmutable('2026-01-15 13:45:30');
        $parsed = [['column' => 'created_at', 'direction' => 'ASC']];

        $values = $this->handler->extractCursorValues(['created_at' => $dt], $parsed, null);

        $this->assertSame(['2026-01-15 13:45:30'], $values);
    }

    public function testBackedEnumIsNormalizedToBackingValue(): void
    {
        $parsed = [['column' => 'status', 'direction' => 'ASC']];

        $values = $this->handler->extractCursorValues(
            ['status' => CursorTestStatus::Active],
            $parsed,
            null
        );

        $this->assertSame(['active'], $values);
    }

    public function testUnitEnumIsNormalizedToCaseName(): void
    {
        $parsed = [['column' => 'kind', 'direction' => 'ASC']];

        $values = $this->handler->extractCursorValues(
            ['kind' => CursorTestKind::First],
            $parsed,
            null
        );

        $this->assertSame(['First'], $values);
    }

    public function testNormalizedDateTimeCursorRoundTripsThroughCodec(): void
    {
        $dt = new \DateTimeImmutable('2026-01-15 13:45:30');
        $parsed = [['column' => 'created_at', 'direction' => 'ASC']];
        $values = $this->handler->extractCursorValues(['created_at' => $dt], $parsed, null);

        $codec = new CursorCodec();
        $token = $codec->encode(new Cursor(
            $values,
            CursorDirection::NEXT
        ));
        $decoded = $codec->decode($token);

        // The decoded boundary must be a bindable scalar identical to the stored form,
        // not a JSON-serialized DateTime structure.
        $this->assertSame(['2026-01-15 13:45:30'], $decoded->getValues());
    }

    public function testNullValueIsPreserved(): void
    {
        $parsed = [['column' => 'val', 'direction' => 'ASC']];
        $values = $this->handler->extractCursorValues(['val' => null], $parsed, null);

        $this->assertSame([null], $values);
    }

    public function testNonScalarOrderColumnThrows(): void
    {
        $parsed = [['column' => 'blob', 'direction' => 'ASC']];

        $this->expectException(CursorPaginationException::class);
        $this->expectExceptionMessage('cannot be used as a cursor boundary');

        $this->handler->extractCursorValues(['blob' => new \stdClass()], $parsed, null);
    }

    public function testUnresolvableColumnThrows(): void
    {
        $parsed = [['column' => 'missing', 'direction' => 'ASC']];

        $this->expectException(CursorPaginationException::class);
        $this->expectExceptionMessage('Unable to resolve cursor value');

        $this->handler->extractCursorValues(['other' => 1], $parsed, null);
    }
}

enum CursorTestStatus: string {
    case Active = 'active';
    case Inactive = 'inactive';
}

enum CursorTestKind {
    case First;
    case Second;
}
