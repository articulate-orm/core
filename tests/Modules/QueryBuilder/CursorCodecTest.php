<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Modules\QueryBuilder\Cursor;
use Articulate\Modules\QueryBuilder\CursorCodec;
use Articulate\Modules\QueryBuilder\CursorDirection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CursorCodecTest extends TestCase {
    private CursorCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new CursorCodec();
    }

    public function testEncodeAndDecode(): void
    {
        $cursor = new Cursor(['id' => 42, 'name' => 'test'], CursorDirection::NEXT);

        $token = $this->codec->encode($cursor);
        $decoded = $this->codec->decode($token);

        $this->assertSame($cursor->getValues(), $decoded->getValues());
        $this->assertSame($cursor->getDirection(), $decoded->getDirection());
    }

    public function testEncodeAndDecodeWithPrevDirection(): void
    {
        $cursor = new Cursor(['score' => 99.5], CursorDirection::PREV);

        $token = $this->codec->encode($cursor);
        $decoded = $this->codec->decode($token);

        $this->assertSame($cursor->getValues(), $decoded->getValues());
        $this->assertSame(CursorDirection::PREV, $decoded->getDirection());
    }

    public function testDecodeInvalidToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->codec->decode('not-a-valid-token!!!');
    }

    public function testDecodeEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->codec->decode('');
    }

    /**
     * Mutant: Throw_ → expression-statement on `throw new InvalidArgumentException(...)` when
     * base64_decode fails (CursorCodec.php:25). Without the throw, decode() would continue
     * with $json === false and crash later with a TypeError from json_decode(false, ...)
     * instead of raising the documented InvalidArgumentException.
     */
    public function testDecodeInvalidBase64ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cursor token: base64 decode failed');

        // '!!!!' is not valid base64/base64url alphabet, so base64_decode(..., true) returns false.
        $this->codec->decode('!!!!');
    }

    /**
     * Mutant: LogicalOr → LogicalAnd on
     * `!isset($data['direction']) || !in_array($data['direction'], ['next', 'prev'], true)`
     * (CursorCodec.php:34). With AND, a token whose direction key IS set but holds an invalid
     * value (neither 'next' nor 'prev') would incorrectly bypass the validation guard, because
     * `!isset(...)` is false for a set key, short-circuiting the AND to false regardless of the
     * invalid value.
     */
    public function testDecodeRejectsSetButInvalidDirectionValue(): void
    {
        $json = json_encode(['values' => ['id' => 1], 'direction' => 'sideways']);
        $token = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cursor token: missing or invalid direction');

        $this->codec->decode($token);
    }

    /**
     * Mutant: DecrementInteger/IncrementInteger on the exception code `0` passed to
     * InvalidArgumentException in the \JsonException catch block (CursorCodec.php:43).
     * Pins the exact code (0), distinguishing it from -1 or 1.
     */
    public function testDecodeJsonFailureWrapsExceptionWithCodeZero(): void
    {
        // Valid base64 but not valid JSON once decoded, forcing JSON_THROW_ON_ERROR to throw.
        $invalidJson = '{not valid json';
        $token = rtrim(strtr(base64_encode($invalidJson), '+/', '-_'), '=');

        try {
            $this->codec->decode($token);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertSame('Invalid cursor token: JSON decode failed', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }
}
