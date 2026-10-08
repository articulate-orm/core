<?php

namespace Articulate\Tests\Attributes\Reflection;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Attributes\Reflection\ReflectionRelation;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Attributes\Relations\MorphMany;
use Articulate\Attributes\Relations\MorphOne;
use Articulate\Attributes\Relations\MorphTo;
use Articulate\Attributes\Relations\OneToMany;
use Articulate\Attributes\Relations\OneToOne;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Entity]
class RelBehaviourTarget {
    #[PrimaryKey]
    #[Property]
    public string $id;

    #[Property]
    public string $name;
}

#[Entity]
class RelBehaviourTargetWithoutPk {
    #[Property]
    public string $name;
}

#[Entity]
class RelBehaviourTargetCodePk {
    #[PrimaryKey]
    #[Property]
    public string $code;
}

#[Entity]
class RelBehaviourOwner {
    #[PrimaryKey]
    #[Property]
    public int $id;

    #[ManyToOne(onDelete: 'CASCADE')]
    public RelBehaviourTarget $inferredTarget;

    #[ManyToOne(targetEntity: RelBehaviourTargetWithoutPk::class)]
    public ?RelBehaviourTargetWithoutPk $pkLessTarget;

    #[ManyToOne(targetEntity: RelBehaviourTarget::class, foreignKey: false)]
    public ?RelBehaviourTarget $withoutForeignKey;

    #[OneToOne(targetEntity: RelBehaviourTarget::class, referencedBy: 'owner')]
    public ?RelBehaviourTarget $inverseConfigured;

    #[OneToOne(targetEntity: RelBehaviourTarget::class, ownedBy: 'owner')]
    public ?RelBehaviourTarget $ownedByTarget;

    #[OneToOne(targetEntity: RelBehaviourTarget::class)]
    public ?RelBehaviourTarget $standaloneOneToOne;

    #[OneToMany(targetEntity: RelBehaviourTarget::class, ownedBy: 'owner')]
    public array $children;

    #[OneToMany(targetEntity: RelBehaviourTarget::class, ownedBy: 'owner')]
    public string $brokenCollection;

    #[MorphTo(typeColumn: 'subject_kind', idColumn: 'subject_ref')]
    public ?object $subject;

    #[MorphOne(targetEntity: RelBehaviourTarget::class, morphType: 'custom-morph', typeColumn: 'one_kind', idColumn: 'one_ref')]
    public ?RelBehaviourTarget $morphOne;

    #[MorphMany(targetEntity: RelBehaviourTarget::class, typeColumn: 'many_kind', idColumn: 'many_ref')]
    public iterable $morphMany;

    // No explicit targetEntity: getTargetEntity() must validate the inferred
    // non-entity target and reject it (ReflectionRelation::validateAndReturnEntity
    // called from the MorphOne/MorphMany branch of resolvePolymorphicTarget()).
    #[MorphMany(targetEntity: RelBehaviourNonEntity::class)]
    public iterable $morphManyInvalidTarget;

    // No explicit targetEntity and no collection-typed property: must hit the
    // OneToMany-without-explicit-target branch (assertOneToManyCollectionType
    // called directly from resolveRegularTarget(), not via validateAndReturnEntity).
    #[OneToMany]
    public string $oneToManyNoExplicitTargetBadType;

    // No explicit targetEntity and a builtin (non-class) property type: must
    // fall straight through to "Target entity is misconfigured", never
    // reaching validateAndReturnEntity() with a builtin type name.
    #[ManyToOne]
    public string $manyToOneNoExplicitTargetBuiltinType;

    // OneToMany with neither ownedBy nor referencedBy configured at all:
    // getInversedBy() must still enforce mapping configuration.
    #[OneToMany(targetEntity: RelBehaviourTarget::class)]
    public array $oneToManyNoMappingConfigured;

    // Untyped property: getType() returns null, so allowsNull() is never
    // called — isNullable() must fall back to false via the null-safe ??.
    #[ManyToOne(targetEntity: RelBehaviourTarget::class)]
    public $untypedManyToOne;

    #[ManyToOne(targetEntity: RelBehaviourTargetCodePk::class)]
    public RelBehaviourTargetCodePk $codePkTarget;
}

class RelBehaviourNonEntity {
    public string $name;
}

#[Entity]
class RelBehaviourConflicting {
    #[PrimaryKey]
    #[Property]
    public int $id;

    #[OneToOne(targetEntity: RelBehaviourTarget::class, ownedBy: 'owner', referencedBy: 'other')]
    public ?RelBehaviourTarget $conflicting;
}

class ReflectionRelationBehaviourTest extends TestCase {
    public function testGetOnDeleteReturnsConfiguredValueAndNullForMorphTo(): void
    {
        $this->assertSame('CASCADE', $this->relation('inferredTarget')->getOnDelete());
        $this->assertNull($this->relation('withoutForeignKey')->getOnDelete());
        $this->assertNull($this->relation('subject')->getOnDelete());
    }

    public function testTargetEntityIsInferredFromPropertyTypeWithoutCollectionCheck(): void
    {
        $relation = $this->relation('inferredTarget');

        $this->assertSame(RelBehaviourTarget::class, $relation->getTargetEntity());
        $this->assertSame('string', $relation->getType());
    }

    public function testGetTypeFallsBackToIntWhenTargetHasNoPrimaryKey(): void
    {
        $this->assertSame('int', $this->relation('pkLessTarget')->getType());
    }

    public function testIsForeignKeyRequiredDependsOnRelationKind(): void
    {
        $this->assertTrue($this->relation('inferredTarget')->isForeignKeyRequired());
        $this->assertFalse($this->relation('withoutForeignKey')->isForeignKeyRequired());
        $this->assertFalse($this->relation('children')->isForeignKeyRequired());
        $this->assertFalse($this->relation('ownedByTarget')->isForeignKeyRequired());
        $this->assertTrue($this->relation('inverseConfigured')->isForeignKeyRequired());
    }

    public function testIsNullableFollowsPropertyTypeAndExplicitOverride(): void
    {
        $this->assertFalse($this->relation('inferredTarget')->isNullable());
        $this->assertTrue($this->relation('withoutForeignKey')->isNullable());
    }

    public function testGetInversedByUsesConfiguredValueOrDerivesFromDeclaringClass(): void
    {
        $this->assertSame('owner', $this->relation('inverseConfigured')->getInversedBy());
        $this->assertSame('rel_behaviour_owner_id', $this->relation('ownedByTarget')->getInversedBy());
    }

    public function testGetInversedByReturnsNullForStandaloneOwningOneToOne(): void
    {
        $this->assertNull($this->relation('standaloneOneToOne')->getInversedBy());
    }

    public function testGetInversedByRejectsBothSidesConfigured(): void
    {
        $relation = $this->relationOf(RelBehaviourConflicting::class, 'conflicting');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ownedBy and referencedBy cannot be specified at the same time');
        $relation->getInversedBy();
    }

    public function testGetMappedByDerivesColumnWhenOnlyInverseSideConfigured(): void
    {
        $this->assertSame('owner', $this->relation('ownedByTarget')->getMappedBy());
        $this->assertSame('inverse_configured_id', $this->relation('inverseConfigured')->getMappedBy());
    }

    public function testOneToManyRejectsNonCollectionPropertyType(): void
    {
        $relation = $this->relation('brokenCollection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('One-to-many property must be iterable collection');
        $relation->getTargetEntity();
    }

    public function testMorphColumnAccessorsForEveryMorphAttribute(): void
    {
        $this->assertSame('subject_kind', $this->relation('subject')->getMorphTypeColumnName());
        $this->assertSame('subject_ref', $this->relation('subject')->getMorphIdColumnName());

        $this->assertSame('one_kind', $this->relation('morphOne')->getMorphTypeColumnName());
        $this->assertSame('one_ref', $this->relation('morphOne')->getMorphIdColumnName());

        $this->assertSame('many_kind', $this->relation('morphMany')->getMorphTypeColumnName());
        $this->assertSame('many_ref', $this->relation('morphMany')->getMorphIdColumnName());
    }

    public function testGetMorphTypeOnlyAvailableForOwningMorphRelations(): void
    {
        $this->assertSame('custom-morph', $this->relation('morphOne')->getMorphType());
        $this->assertSame(RelBehaviourTarget::class, $this->relation('morphMany')->getMorphType());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not an owning polymorphic relation');
        $this->relation('subject')->getMorphType();
    }

    public function testMorphColumnAccessorsRejectNonPolymorphicRelations(): void
    {
        $relation = $this->relation('inferredTarget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not a polymorphic relation');
        $relation->getMorphIdColumnName();
    }

    public function testMorphManyWithNonEntityTargetIsRejected(): void
    {
        $relation = $this->relation('morphManyInvalidTarget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Non-entity found in relation');
        $relation->getTargetEntity();
    }

    public function testOneToManyWithoutExplicitTargetAndBuiltinPropertyTypeRejectsBadCollection(): void
    {
        $relation = $this->relation('oneToManyNoExplicitTargetBadType');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('One-to-many property must be iterable collection');
        $relation->getTargetEntity();
    }

    public function testManyToOneWithoutExplicitTargetAndBuiltinPropertyTypeIsMisconfigured(): void
    {
        $relation = $this->relation('manyToOneNoExplicitTargetBuiltinType');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Target entity is misconfigured');
        $relation->getTargetEntity();
    }

    public function testGetMappedByRequiresMappingConfigurationForStandaloneOneToOne(): void
    {
        $relation = $this->relation('standaloneOneToOne');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Either ownedBy or referencedBy is required');
        $relation->getMappedBy();
    }

    public function testGetInversedByRequiresMappingConfigurationForUnconfiguredOneToMany(): void
    {
        $relation = $this->relation('oneToManyNoMappingConfigured');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Either ownedBy or referencedBy is required');
        $relation->getInversedBy();
    }

    public function testIsNullableFallsBackToFalseForUntypedProperty(): void
    {
        $this->assertFalse($this->relation('untypedManyToOne')->isNullable());
    }

    public function testGetReferencedColumnNameUsesActualPrimaryKeyColumnNotHardcodedId(): void
    {
        $this->assertSame('code', $this->relation('codePkTarget')->getReferencedColumnName());
    }

    public function testIsPolymorphicIsPubliclyCallable(): void
    {
        $relation = $this->relation('subject');

        $this->assertTrue($relation->isPolymorphic());
        $this->assertFalse($this->relation('inferredTarget')->isPolymorphic());
    }

    public function testGetInversedByPropertyIsPubliclyCallable(): void
    {
        $relation = $this->relation('inverseConfigured');

        $this->assertSame('owner', $relation->getInversedByProperty());
    }

    private function relation(string $propertyName): ReflectionRelation
    {
        return $this->relationOf(RelBehaviourOwner::class, $propertyName);
    }

    private function relationOf(string $entityClass, string $propertyName): ReflectionRelation
    {
        foreach (new ReflectionEntity($entityClass)->getEntityRelationProperties() as $relation) {
            if ($relation instanceof ReflectionRelation && $relation->getPropertyName() === $propertyName) {
                return $relation;
            }
        }

        $this->fail("Relation '{$propertyName}' not found on {$entityClass}");
    }
}
