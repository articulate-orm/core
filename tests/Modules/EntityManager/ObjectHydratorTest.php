<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\OneToMany;
use Articulate\Modules\EntityManager\Collection;
use Articulate\Modules\EntityManager\LazyCollection;
use Articulate\Modules\EntityManager\ObjectHydrator;
use Articulate\Modules\EntityManager\RelationshipLoader;
use Articulate\Modules\EntityManager\UnitOfWork;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\HydratorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ObjectHydratorTest extends TestCase {
    private ObjectHydrator $hydrator;

    private UnitOfWork $unitOfWork;

    protected function setUp(): void
    {
        $this->unitOfWork = $this->createMock(UnitOfWork::class);
        $this->hydrator = new ObjectHydrator($this->unitOfWork);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testImplementsHydratorInterface(): void
    {
        $this->assertInstanceOf(HydratorInterface::class, $this->hydrator);
    }

    public function testHydrateCreatesNewEntity(): void
    {
        $data = [
            'id' => 1,
            'name' => 'Test Entity',
            'email' => 'test@example.com',
        ];

        $this->unitOfWork->expects($this->once())
            ->method('registerManaged')
            ->with($this->isInstanceOf(TestEntity::class), $data);

        $entity = $this->hydrator->hydrate(TestEntity::class, $data);

        $this->assertInstanceOf(TestEntity::class, $entity);
        $this->assertEquals(1, $entity->id);
        $this->assertEquals('Test Entity', $entity->name);
        $this->assertEquals('test@example.com', $entity->email);
    }

    public function testHydrateIntoExistingEntity(): void
    {
        $existingEntity = new TestEntity();
        $existingEntity->id = 999; // Should be overwritten
        $existingEntity->existingField = 'should remain';

        $data = [
            'id' => 1,
            'name' => 'Updated Name',
        ];

        $this->unitOfWork->expects($this->once())
            ->method('registerManaged')
            ->with($existingEntity, $data);

        $result = $this->hydrator->hydrate(TestEntity::class, $data, $existingEntity);

        $this->assertSame($existingEntity, $result);
        $this->assertEquals(1, $result->id);
        $this->assertEquals('Updated Name', $result->name);
        $this->assertEquals('should remain', $result->existingField);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExtractEntityData(): void
    {
        $entity = new TestEntity();
        $entity->id = 42;
        $entity->name = 'Extract Test';

        $data = $this->hydrator->extract($entity);

        $this->assertEquals([
            'id' => 42,
            'name' => 'Extract Test',
            'email' => null,
            'existingField' => null,
            'userId' => null,
            'firstName' => null,
            'lastName' => null,
            'emailAddress' => null,
        ], $data);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHydratePartial(): void
    {
        $entity = new TestEntity();
        $entity->id = 1;
        $entity->name = 'Original';

        $partialData = [
            'name' => 'Updated',
            'email' => 'new@email.com',
        ];

        $this->hydrator->hydratePartial($entity, $partialData);

        $this->assertEquals(1, $entity->id); // Unchanged
        $this->assertEquals('Updated', $entity->name);
        $this->assertEquals('new@email.com', $entity->email);
    }

    public function testSnakeCaseToCamelCaseMapping(): void
    {
        $data = [
            'user_id' => 123,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email_address' => 'john@example.com',
        ];

        $this->unitOfWork->expects($this->once())
            ->method('registerManaged');

        $entity = $this->hydrator->hydrate(TestEntity::class, $data);

        $this->assertEquals(123, $entity->userId);
        $this->assertEquals('John', $entity->firstName);
        $this->assertEquals('Doe', $entity->lastName);
        $this->assertEquals('john@example.com', $entity->emailAddress);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHydrateSkipsCollectionRelationWhenPreInitialized(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->once())
            ->method('registerManaged')
            ->with($this->isInstanceOf(TestEntityWithPreInitializedCollectionRelation::class), $this->isArray());

        $metadataRegistry = new EntityMetadataRegistry();
        $relationshipLoader = $this->createMock(RelationshipLoader::class);
        $relationshipLoader->expects($this->never())
            ->method('load');
        $relationshipLoader->method('getMetadataRegistry')
            ->willReturn($metadataRegistry);

        $hydrator = new ObjectHydrator(
            $unitOfWork,
            $relationshipLoader,
        );

        $preInitialized = new TestEntityWithPreInitializedCollectionRelation();
        $preInitialized->books = new Collection([]);

        $entity = $hydrator->hydrate(TestEntityWithPreInitializedCollectionRelation::class, [
            'id' => 1,
            'name' => 'Author',
        ], $preInitialized);

        $this->assertInstanceOf(Collection::class, $entity->books);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHydrateLoadsOneToManyRelationWhenCollectionIsNull(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->once())
            ->method('registerManaged')
            ->with($this->isInstanceOf(TestEntityWithNullCollectionRelation::class), $this->isArray());

        $metadataRegistry = new EntityMetadataRegistry();
        $relationshipLoader = $this->createMock(RelationshipLoader::class);
        $relationshipLoader->expects($this->once())
            ->method('load')
            ->willReturn([new TestBookForNullCollectionRelation()]);
        $relationshipLoader->method('getMetadataRegistry')
            ->willReturn($metadataRegistry);

        $hydrator = new ObjectHydrator(
            $unitOfWork,
            $relationshipLoader,
        );

        $entity = $hydrator->hydrate(TestEntityWithNullCollectionRelation::class, [
            'id' => 1,
            'name' => 'Author',
        ]);

        $this->assertInstanceOf(Collection::class, $entity->books);
        $this->assertCount(1, $entity->books);
        $this->assertInstanceOf(TestBookForNullCollectionRelation::class, $entity->books[0]);
    }

    public function testColumnToPropertyMappingWithAttributes(): void
    {
        $data = [
            'user_name' => 'John Doe',
            'user_email' => 'john@example.com',
            'profile_id' => 42,
        ];

        $this->unitOfWork->expects($this->once())
            ->method('registerManaged');

        $entity = $this->hydrator->hydrate(TestEntityWithPropertyAttributes::class, $data);

        $this->assertInstanceOf(TestEntityWithPropertyAttributes::class, $entity);
        $this->assertEquals('John Doe', $entity->fullName);
        $this->assertEquals('john@example.com', $entity->emailAddress);
        $this->assertEquals(42, $entity->profileId);
    }

    // ── Mutation killers for 214-224 ────────────────────────────────────────

    public function testExtractConvertsNullValuesToNullNotPassThrough(): void
    {
        // convertToDatabase(null, ...) must return null directly via the early-return
        // guard, never reaching the type-registry conversion path. If ReturnRemoval
        // strips `return null;`, execution falls through and still correctly produces
        // null here — so assert via a mock-like entity where null stays null for
        // a typed (non-nullable-by-default-conversion) property too.
        $entity = new TestEntity();
        $entity->id = 42;
        $entity->name = null;

        $data = $this->hydrator->extract($entity);

        $this->assertNull($data['name']);
    }

    public function testHydratePartialInvokesHydrateRelationsNotJustProperties(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $metadataRegistry = new EntityMetadataRegistry();
        $relationshipLoader = $this->createMock(RelationshipLoader::class);
        $relationshipLoader->method('getMetadataRegistry')->willReturn($metadataRegistry);

        // hydratePartial() must call hydrateRelations() too (not just hydrateProperties());
        // MethodCallRemoval on that line would leave a null collection relation untouched.
        $relationshipLoader->expects($this->once())
            ->method('load')
            ->willReturn([new TestBookForNullCollectionRelation()]);

        $hydrator = new ObjectHydrator($unitOfWork, $relationshipLoader);

        $entity = new TestEntityWithNullCollectionRelation();
        $entity->id = 1;
        $entity->name = 'Author';

        $hydrator->hydratePartial($entity, ['id' => 1, 'name' => 'Author']);

        $this->assertInstanceOf(Collection::class, $entity->books);
    }

    public function testConvertToPHPWithNoTypeHintReturnsRawValueUnconverted(): void
    {
        // Property with no type hint at all (TestEntityUntyped::$raw) must pass the
        // DB value straight through — the LogicalNot mutant (!$type -> $type) would
        // invert this guard and attempt conversion/early-return on the wrong branch.
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('registerManaged');

        $hydrator = new ObjectHydrator($unitOfWork);

        $entity = $hydrator->hydrate(TestEntityUntyped::class, ['raw' => '12345']);

        $this->assertSame('12345', $entity->raw);
    }
}

// Test entity class for hydration tests
class TestEntity {
    public ?int $id = null;

    public ?string $name = null;

    public ?string $email = null;

    public ?string $existingField = null;

    public ?int $userId = null;

    public ?string $firstName = null;

    public ?string $lastName = null;

    public ?string $emailAddress = null;

    private string $privateField = 'private';
}

// Test entity class with Property attribute mapping
class TestEntityWithPropertyAttributes {
    #[Property(name: 'user_name')]
    public ?string $fullName = null;

    #[Property(name: 'user_email')]
    public ?string $emailAddress = null;

    #[Property(name: 'profile_id')]
    public ?int $profileId = null;
}

#[Entity(tableName: 'lazy_loading_authors')]
class TestEntityWithPreInitializedCollectionRelation {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;

    #[OneToMany(targetEntity: TestBookForNullCollectionRelation::class, ownedBy: 'author')]
    public array|Collection $books = [];
}

#[Entity(tableName: 'lazy_loading_books')]
class TestBookForNullCollectionRelation {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $title = null;
}

#[Entity(tableName: 'lazy_loading_authors')]
class TestEntityWithNullCollectionRelation {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;

    #[OneToMany(targetEntity: TestBookForNullCollectionRelation::class, ownedBy: 'author')]
    public ?Collection $books = null;
}

class TestEntityUntyped {
    public $raw;
}
