<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Tests\DatabaseTestCase;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

enum RoundTripStringEnum: string {
    case Active = 'active';
    case Archived = 'archived';
}

enum RoundTripIntEnum: int {
    case Low = 1;
    case High = 2;
}

enum RoundTripPureEnum {
    case Draft;
    case Published;
}

#[Entity(tableName: 'type_round_trip_items')]
class TypeRoundTripItem {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public int $intValue;

    #[Property]
    public float $floatValue;

    #[Property]
    public string $stringValue;

    #[Property]
    public bool $boolValue;

    /** @var array<string, mixed> */
    #[Property]
    public array $arrayValue;

    #[Property]
    public DateTime $dateTime;

    #[Property]
    public DateTimeImmutable $dateTimeImmutable;

    #[Property]
    public RoundTripStringEnum $stringEnum;

    #[Property]
    public RoundTripIntEnum $intEnum;

    #[Property]
    public RoundTripPureEnum $pureEnum;

    #[Property]
    public ?int $nullableInt = null;

    #[Property]
    public ?float $nullableFloat = null;

    #[Property]
    public ?string $nullableString = null;

    #[Property]
    public ?bool $nullableBool = null;

    /** @var array<string, mixed>|null */
    #[Property]
    public ?array $nullableArray = null;

    #[Property]
    public ?DateTime $nullableDateTime = null;

    #[Property]
    public ?DateTimeImmutable $nullableDateTimeImmutable = null;

    #[Property]
    public ?RoundTripStringEnum $nullableStringEnum = null;

    #[Property]
    public ?RoundTripIntEnum $nullableIntEnum = null;

    #[Property]
    public ?RoundTripPureEnum $nullablePureEnum = null;
}

/**
 * Persist → fresh EntityManager → find() for every type TypeRegistry maps, so a converter
 * that only works when called with arguments the hydrator never passes can't hide (see #55).
 */
class TypeRoundTripTest extends DatabaseTestCase {
    protected function tearDownTestTables(Connection $connection, string $databaseName): void
    {
        $this->setCurrentDatabase($connection, $databaseName);
        $this->cleanUpTables(['type_round_trip_items']);
    }

    #[DataProvider('databaseProvider')]
    public function testNonNullValuesRoundTripWithTheirDeclaredTypes(string $databaseName): void
    {
        $connection = $this->createTable($databaseName);
        $item = $this->newItem();
        $item->nullableInt = 7;
        $item->nullableFloat = 2.5;
        $item->nullableString = 'maybe';
        $item->nullableBool = false;
        $item->nullableArray = ['b' => [1, 2]];
        $item->nullableDateTime = new DateTime('2026-03-04 05:06:07');
        $item->nullableDateTimeImmutable = new DateTimeImmutable('2026-04-05 06:07:08');
        $item->nullableStringEnum = RoundTripStringEnum::Archived;
        $item->nullableIntEnum = RoundTripIntEnum::Low;
        $item->nullablePureEnum = RoundTripPureEnum::Draft;

        $loaded = $this->persistAndReload($connection, $item);

        $this->assertSame(42, $loaded->intValue);
        $this->assertSame(1.5, $loaded->floatValue);
        $this->assertSame('text', $loaded->stringValue);
        $this->assertTrue($loaded->boolValue);
        $this->assertSame(['a' => 1], $loaded->arrayValue);
        $this->assertInstanceOf(DateTime::class, $loaded->dateTime);
        $this->assertSame('2026-01-02 03:04:05', $loaded->dateTime->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->dateTimeImmutable);
        $this->assertSame('2026-02-03 04:05:06', $loaded->dateTimeImmutable->format('Y-m-d H:i:s'));
        $this->assertSame(RoundTripStringEnum::Active, $loaded->stringEnum);
        $this->assertSame(RoundTripIntEnum::High, $loaded->intEnum);
        $this->assertSame(RoundTripPureEnum::Published, $loaded->pureEnum);

        $this->assertSame(7, $loaded->nullableInt);
        $this->assertSame(2.5, $loaded->nullableFloat);
        $this->assertSame('maybe', $loaded->nullableString);
        $this->assertFalse($loaded->nullableBool);
        $this->assertSame(['b' => [1, 2]], $loaded->nullableArray);
        $this->assertInstanceOf(DateTime::class, $loaded->nullableDateTime);
        $this->assertSame('2026-03-04 05:06:07', $loaded->nullableDateTime->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->nullableDateTimeImmutable);
        $this->assertSame('2026-04-05 06:07:08', $loaded->nullableDateTimeImmutable->format('Y-m-d H:i:s'));
        $this->assertSame(RoundTripStringEnum::Archived, $loaded->nullableStringEnum);
        $this->assertSame(RoundTripIntEnum::Low, $loaded->nullableIntEnum);
        $this->assertSame(RoundTripPureEnum::Draft, $loaded->nullablePureEnum);
    }

    #[DataProvider('databaseProvider')]
    public function testNullValuesRoundTripAsNull(string $databaseName): void
    {
        $connection = $this->createTable($databaseName);

        $loaded = $this->persistAndReload($connection, $this->newItem());

        $this->assertNull($loaded->nullableInt);
        $this->assertNull($loaded->nullableFloat);
        $this->assertNull($loaded->nullableString);
        $this->assertNull($loaded->nullableBool);
        $this->assertNull($loaded->nullableArray);
        $this->assertNull($loaded->nullableDateTime);
        $this->assertNull($loaded->nullableDateTimeImmutable);
        $this->assertNull($loaded->nullableStringEnum);
        $this->assertNull($loaded->nullableIntEnum);
        $this->assertNull($loaded->nullablePureEnum);
    }

    #[DataProvider('databaseProvider')]
    public function testUpdatedValuesRoundTripWithTheirDeclaredTypes(string $databaseName): void
    {
        $connection = $this->createTable($databaseName);
        $writer = new EntityManager($connection);
        $item = $this->newItem();
        $writer->persist($item);
        $writer->flush();

        $item->arrayValue = ['changed' => true];
        $item->dateTime = new DateTime('2027-01-01 00:00:01');
        $item->dateTimeImmutable = new DateTimeImmutable('2027-01-01 00:00:02');
        $item->nullableDateTimeImmutable = new DateTimeImmutable('2027-01-01 00:00:03');
        $item->stringEnum = RoundTripStringEnum::Archived;
        $writer->flush();

        $loaded = (new EntityManager($connection))->find(TypeRoundTripItem::class, $item->id);

        $this->assertSame(['changed' => true], $loaded->arrayValue);
        $this->assertSame('2027-01-01 00:00:01', $loaded->dateTime->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-01 00:00:02', $loaded->dateTimeImmutable->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->nullableDateTimeImmutable);
        $this->assertSame('2027-01-01 00:00:03', $loaded->nullableDateTimeImmutable->format('Y-m-d H:i:s'));
        $this->assertSame(RoundTripStringEnum::Archived, $loaded->stringEnum);
    }

    private function newItem(): TypeRoundTripItem
    {
        $item = new TypeRoundTripItem();
        $item->intValue = 42;
        $item->floatValue = 1.5;
        $item->stringValue = 'text';
        $item->boolValue = true;
        $item->arrayValue = ['a' => 1];
        $item->dateTime = new DateTime('2026-01-02 03:04:05');
        $item->dateTimeImmutable = new DateTimeImmutable('2026-02-03 04:05:06');
        $item->stringEnum = RoundTripStringEnum::Active;
        $item->intEnum = RoundTripIntEnum::High;
        $item->pureEnum = RoundTripPureEnum::Published;

        return $item;
    }

    private function persistAndReload(Connection $connection, TypeRoundTripItem $item): TypeRoundTripItem
    {
        $writer = new EntityManager($connection);
        $writer->persist($item);
        $writer->flush();

        $loaded = (new EntityManager($connection))->find(TypeRoundTripItem::class, $item->id);
        $this->assertInstanceOf(TypeRoundTripItem::class, $loaded);
        $this->assertNotSame($item, $loaded);

        return $loaded;
    }

    private function createTable(string $databaseName): Connection
    {
        $connection = $this->getConnection($databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $connection->executeQuery('DROP TABLE IF EXISTS type_round_trip_items');
        $connection->executeQuery(match ($databaseName) {
            'mysql' => 'CREATE TABLE type_round_trip_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                int_value INT NOT NULL, float_value DOUBLE NOT NULL, string_value VARCHAR(255) NOT NULL,
                bool_value TINYINT(1) NOT NULL, array_value JSON NOT NULL,
                date_time DATETIME NOT NULL, date_time_immutable DATETIME NOT NULL,
                string_enum VARCHAR(255) NOT NULL, int_enum INT NOT NULL, pure_enum VARCHAR(255) NOT NULL,
                nullable_int INT NULL, nullable_float DOUBLE NULL, nullable_string VARCHAR(255) NULL,
                nullable_bool TINYINT(1) NULL, nullable_array JSON NULL,
                nullable_date_time DATETIME NULL, nullable_date_time_immutable DATETIME NULL,
                nullable_string_enum VARCHAR(255) NULL, nullable_int_enum INT NULL, nullable_pure_enum VARCHAR(255) NULL
            )',
            'pgsql' => 'CREATE TABLE type_round_trip_items (
                id SERIAL PRIMARY KEY,
                int_value INTEGER NOT NULL, float_value DOUBLE PRECISION NOT NULL, string_value VARCHAR(255) NOT NULL,
                bool_value BOOLEAN NOT NULL, array_value JSONB NOT NULL,
                date_time TIMESTAMP NOT NULL, date_time_immutable TIMESTAMP NOT NULL,
                string_enum VARCHAR(255) NOT NULL, int_enum INTEGER NOT NULL, pure_enum VARCHAR(255) NOT NULL,
                nullable_int INTEGER NULL, nullable_float DOUBLE PRECISION NULL, nullable_string VARCHAR(255) NULL,
                nullable_bool BOOLEAN NULL, nullable_array JSONB NULL,
                nullable_date_time TIMESTAMP NULL, nullable_date_time_immutable TIMESTAMP NULL,
                nullable_string_enum VARCHAR(255) NULL, nullable_int_enum INTEGER NULL, nullable_pure_enum VARCHAR(255) NULL
            )',
            default => throw new \InvalidArgumentException("Unsupported database: {$databaseName}"),
        });

        return $connection;
    }
}
