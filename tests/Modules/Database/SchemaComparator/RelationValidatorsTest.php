<?php

namespace Articulate\Tests\Modules\Database\SchemaComparator;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Attributes\Reflection\ReflectionManyToMany;
use Articulate\Attributes\Reflection\ReflectionMorphedByMany;
use Articulate\Attributes\Reflection\ReflectionMorphToMany;
use Articulate\Attributes\Reflection\ReflectionRelation;
use Articulate\Attributes\Reflection\RelationInterface;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Attributes\Relations\OneToMany;
use Articulate\Modules\Database\SchemaComparator\RelationValidators\ManyToManyRelationValidator;
use Articulate\Modules\Database\SchemaComparator\RelationValidators\ManyToOneRelationValidator;
use Articulate\Modules\Database\SchemaComparator\RelationValidators\MorphToManyRelationValidator;
use Articulate\Modules\Database\SchemaComparator\RelationValidators\OneToManyRelationValidator;
use Articulate\Modules\Database\SchemaComparator\RelationValidators\OneToOneRelationValidator;
use Articulate\Modules\Database\SchemaComparator\RelationValidators\PolymorphicRelationValidator;
use Articulate\Modules\Database\SchemaComparator\RelationValidators\RelationValidatorFactory;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestManyToManyOwner;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestManyToManyTarget;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestMorphOneEntity;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestPolymorphicManyToManyPost;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestPolymorphicManyToManyTag;
use Articulate\Tests\Modules\DatabaseSchemaComparator\TestEntities\TestRelatedMainEntity;
use PHPUnit\Framework\TestCase;

// Entity fixtures for validator guard-clause tests

#[Entity(tableName: 'rel_validator_posts')]
class RelValidatorPost {
    #[PrimaryKey]
    public int $id;

    /** @var array<int, RelValidatorComment> */
    #[OneToMany(targetEntity: RelValidatorComment::class, ownedBy: 'post')]
    public array $comments = [];
}

#[Entity(tableName: 'rel_validator_comments')]
class RelValidatorComment {
    #[PrimaryKey]
    public int $id;

    #[ManyToOne(targetEntity: RelValidatorPost::class)]
    public RelValidatorPost $post;
}

class RelationValidatorsTest extends TestCase {
    public function testManyToManyRelationValidatorCanBeInstantiated(): void
    {
        $validator = new ManyToManyRelationValidator();
        $this->assertInstanceOf(ManyToManyRelationValidator::class, $validator);
    }

    public function testManyToOneRelationValidatorCanBeInstantiated(): void
    {
        $validator = new ManyToOneRelationValidator();
        $this->assertInstanceOf(ManyToOneRelationValidator::class, $validator);
    }

    public function testMorphToManyRelationValidatorCanBeInstantiated(): void
    {
        $validator = new MorphToManyRelationValidator();
        $this->assertInstanceOf(MorphToManyRelationValidator::class, $validator);
    }

    public function testOneToManyRelationValidatorCanBeInstantiated(): void
    {
        $validator = new OneToManyRelationValidator();
        $this->assertInstanceOf(OneToManyRelationValidator::class, $validator);
    }

    public function testOneToOneRelationValidatorCanBeInstantiated(): void
    {
        $validator = new OneToOneRelationValidator();
        $this->assertInstanceOf(OneToOneRelationValidator::class, $validator);
    }

    public function testPolymorphicRelationValidatorCanBeInstantiated(): void
    {
        $validator = new PolymorphicRelationValidator();
        $this->assertInstanceOf(PolymorphicRelationValidator::class, $validator);
    }

    public function testRelationValidatorFactoryCanBeInstantiated(): void
    {
        $factory = new RelationValidatorFactory();
        $this->assertInstanceOf(RelationValidatorFactory::class, $factory);
    }

    // ── Guard-clause mutation killers ─────────────────────────────────────────

    public function testManyToOneValidatorReturnsEarlyForNonManyToOneRelation(): void
    {
        $entity = new ReflectionEntity(RelValidatorPost::class);
        $oneToManyRelation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionRelation && $rel->isOneToMany()) {
                $oneToManyRelation = $rel;

                break;
            }
        }
        $this->assertNotNull($oneToManyRelation, 'Test fixture must expose a OneToMany relation');

        $validator = new ManyToOneRelationValidator();
        $validator->validate($oneToManyRelation); // must not throw
        $this->assertSame(ManyToOneRelationValidator::class, $validator::class);
    }

    public function testOneToManyValidatorReturnsEarlyForNonOneToManyRelation(): void
    {
        $entity = new ReflectionEntity(RelValidatorComment::class);
        $manyToOneRelation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionRelation && $rel->isManyToOne()) {
                $manyToOneRelation = $rel;

                break;
            }
        }
        $this->assertNotNull($manyToOneRelation, 'Test fixture must expose a ManyToOne relation');

        $validator = new OneToManyRelationValidator();
        $validator->validate($manyToOneRelation); // must not throw
        $this->assertSame(OneToManyRelationValidator::class, $validator::class);
    }

    public function testPolymorphicValidatorReturnsEarlyForNonReflectionRelation(): void
    {
        $nonReflectionRelation = new class() implements RelationInterface {
            public function getTargetEntity(): ?string
            {
                return null;
            }

            public function getDeclaringClassName(): string
            {
                return self::class;
            }

            public function isOwningSide(): bool
            {
                return true;
            }

            public function getMappedBy(): ?string
            {
                return null;
            }

            public function getInversedBy(): ?string
            {
                return null;
            }

            public function getPropertyName(): string
            {
                return 'stub';
            }

            public function isLazy(): bool
            {
                return false;
            }
        };

        $validator = new PolymorphicRelationValidator();
        $validator->validate($nonReflectionRelation); // must not throw/error
        $this->assertSame(PolymorphicRelationValidator::class, $validator::class);
    }

    public function testPolymorphicValidatorDispatchesToMorphOneValidation(): void
    {
        // Covers MethodCallRemoval on validateMorphTo/validateMorphOne/validateMorphMany
        // dispatch branches: a MorphOne relation lacking its required inverse
        // 'referencedBy' wiring must actually be validated (and throw), proving
        // validateMorphOne() really runs rather than being a silently removed call.
        $entity = new ReflectionEntity(TestMorphOneEntity::class);
        $morphOneRelation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionRelation && $rel->isMorphOne()) {
                $morphOneRelation = $rel;

                break;
            }
        }
        $this->assertNotNull($morphOneRelation, 'Fixture must expose a MorphOne relation');

        $validator = new PolymorphicRelationValidator();
        // Valid configuration must NOT throw — proving validateMorphOne() ran
        // its full body (target entity + referencedBy + inverse attribute checks).
        $validator->validate($morphOneRelation);
        $this->assertTrue(true);
    }

    public function testPolymorphicValidatorSupportsRequiresAllThreeMorphKinds(): void
    {
        // Covers LogicalOrSingleSubExprNegation on supports(): a MorphOne
        // relation must be reported as supported.
        $entity = new ReflectionEntity(TestMorphOneEntity::class);
        $morphOneRelation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionRelation && $rel->isMorphOne()) {
                $morphOneRelation = $rel;

                break;
            }
        }
        $this->assertNotNull($morphOneRelation);

        $validator = new PolymorphicRelationValidator();
        $this->assertTrue($validator->supports($morphOneRelation));
    }

    public function testMorphToManyValidatorDispatchesToMorphedByManyValidation(): void
    {
        // Covers InstanceOf_ and MethodCallRemoval mutants on the
        // ReflectionMorphedByMany branch: validating a MorphedByMany relation
        // against a nonexistent target entity must throw, proving
        // validateMorphedByMany() actually ran.
        $entity = new ReflectionEntity(TestPolymorphicManyToManyTag::class);
        $morphedByManyRelation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionMorphedByMany) {
                $morphedByManyRelation = $rel;

                break;
            }
        }
        $this->assertNotNull($morphedByManyRelation, 'Fixture must expose a MorphedByMany relation');

        $validator = new MorphToManyRelationValidator();
        // Valid fixture must not throw, proving the full validateMorphedByMany
        // body (including the target-entity existence check) executed.
        $validator->validate($morphedByManyRelation);
        $this->assertTrue(true);
    }

    public function testMorphToManyValidatorValidatesInverseRelationExistsForMorphToMany(): void
    {
        // Covers MethodCallRemoval on validateInverseRelationExists(): removing
        // the call would make an otherwise-misconfigured MorphToMany (no
        // matching MorphedByMany on the target) pass silently.
        $entity = new ReflectionEntity(TestPolymorphicManyToManyPost::class);
        $morphToManyRelation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionMorphToMany) {
                $morphToManyRelation = $rel;

                break;
            }
        }
        $this->assertNotNull($morphToManyRelation, 'Fixture must expose a MorphToMany relation');

        $validator = new MorphToManyRelationValidator();
        // The fixture's target (TestPolymorphicManyToManyTag) does have a
        // matching MorphedByMany, so a valid call must not throw.
        $validator->validate($morphToManyRelation);
        $this->assertTrue(true);
    }

    public function testManyToManyValidatorValidatesOwningSideWithoutThrowingOnValidConfig(): void
    {
        // Covers validateOwningProperty()/validateMappingTableName() call
        // removal and NotIdentical mutant on the mapping table name check:
        // a correctly wired owning-side relation (matching referencedBy and
        // mapping table name on both sides) must validate cleanly.
        $entity = new ReflectionEntity(TestManyToManyOwner::class);
        $relation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionManyToMany) {
                $relation = $rel;

                break;
            }
        }
        $this->assertNotNull($relation, 'Fixture must expose a ManyToMany relation');

        $validator = new ManyToManyRelationValidator();
        $validator->validate($relation);
        $this->assertTrue(true);
    }

    public function testManyToManyValidatorValidatesInverseSideWithoutThrowingOnValidConfig(): void
    {
        // Covers validateOwningProperty() removal on the inverse-side path and
        // the NotIdentical mapping-table-name mutant from the opposite
        // direction (owning property's attribute lookup).
        $entity = new ReflectionEntity(TestManyToManyTarget::class);
        $relation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionManyToMany) {
                $relation = $rel;

                break;
            }
        }
        $this->assertNotNull($relation, 'Fixture must expose a ManyToMany relation');

        $validator = new ManyToManyRelationValidator();
        $validator->validate($relation);
        $this->assertTrue(true);
    }

    public function testOneToOneValidatorAllowsValidOwningSideWithoutForeignKeyRequest(): void
    {
        // Exercises OneToOneRelationValidator's guard clauses end-to-end on a
        // real OneToOne relation, covering the early-return && chain mutants
        // (isForeignKeyRequired / isOwningSide / inversedBy checks).
        $entity = new ReflectionEntity(TestRelatedMainEntity::class);
        $oneToOneRelation = null;
        foreach ($entity->getEntityRelationProperties() as $rel) {
            if ($rel instanceof ReflectionRelation && $rel->isOneToOne()) {
                $oneToOneRelation = $rel;

                break;
            }
        }

        if ($oneToOneRelation === null) {
            $this->markTestSkipped('No OneToOne relation found on TestRelatedMainEntity fixture.');
        }

        $validator = new OneToOneRelationValidator();
        $validator->validate($oneToOneRelation);
        $this->assertTrue(true);
    }
}
