<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToOne;
use Articulate\Attributes\Relations\MorphMany;
use Articulate\Modules\EntityManager\Collection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\EntityManager\LazyCollection;
use Articulate\Modules\EntityManager\ObjectHydrator;
use Articulate\Modules\EntityManager\RelationshipLoader;
use Articulate\Modules\EntityManager\UnitOfWork;
use Articulate\Schema\EntityMetadataRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// ── Fixture entities ────────────────────────────────────────────────────────

#[Entity(tableName: 'morph_mutation_comments')]
class MorphMutationComment {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $body = null;
}

/**
 * MorphMany relation, default lazy:false (eager). Used to kill the
 * LogicalAnd->LogicalOr / LogicalOrSingleSubExprNegation mutants on the
 * `$isManyToMany || $isMorphManyToMany || ($relation instanceof ReflectionRelation && $relation->isOneToMany())`
 * guard that decides whether eagerly-loaded array data gets wrapped in a Collection.
 * MorphMany is neither ManyToMany, MorphManyToMany, nor OneToMany, so the real
 * code must leave the raw array untouched.
 */
#[Entity(tableName: 'morph_mutation_eager_owners')]
class MorphMutationEagerOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;

    #[MorphMany(targetEntity: MorphMutationComment::class)]
    public array $comments = [];
}

/**
 * MorphMany relation, lazy:true. Used to kill the isCollectionRelation
 * mutation (isOneToMany || isManyToMany || isMorphMany -> isOneToMany || (isManyToMany && isMorphMany))
 * and the countLoader LogicalOrSingleSubExprNegation mutant.
 */
#[Entity(tableName: 'morph_mutation_lazy_owners')]
class MorphMutationLazyOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;

    #[MorphMany(targetEntity: MorphMutationComment::class, lazy: true)]
    public ?Collection $comments = null;
}

/**
 * Two independent lazy relations on the same entity, used to kill the
 * AssignCoalesce mutant on `$em ??= $this->relationshipLoader->getEntityManager();`
 * (mutated to a plain `=`, which would call getEntityManager() once per lazy
 * relation instead of once per hydrate() call).
 */
#[Entity(tableName: 'morph_mutation_double_lazy_owners')]
class DoubleLazyOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public ?string $name = null;

    #[ManyToOne(targetEntity: MorphMutationComment::class, lazy: true)]
    public ?MorphMutationComment $firstComment = null;

    #[ManyToOne(targetEntity: MorphMutationComment::class, lazy: true, column: 'second_comment_id')]
    public ?MorphMutationComment $secondComment = null;
}

class ObjectHydratorMorphMutationTest extends TestCase {
    private EntityMetadataRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new EntityMetadataRegistry();
    }

    /**
     * @return array{ObjectHydrator, RelationshipLoader&MockObject, EntityManager&MockObject}
     */
    private function buildHydrator(): array
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $em = $this->createMock(EntityManager::class);
        $loader = $this->createMock(RelationshipLoader::class);

        $loader->method('getMetadataRegistry')->willReturn($this->registry);
        $loader->method('getEntityManager')->willReturn($em);

        return [new ObjectHydrator($unitOfWork, $loader), $loader, $em];
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEagerMorphManyLeavesRawArrayUnwrapped(): void
    {
        [$hydrator, $loader] = $this->buildHydrator();

        $comment = new MorphMutationComment();
        $comment->id = 1;

        $loader->expects($this->once())
            ->method('load')
            ->willReturn([$comment]);

        /** @var MorphMutationEagerOwner $entity */
        $entity = $hydrator->hydrate(MorphMutationEagerOwner::class, [
            'id' => 1,
            'name' => 'Owner',
        ]);

        // MorphMany is not ManyToMany/MorphManyToMany/OneToMany, so the real
        // code must NOT wrap it in a Collection — the mutant (AND -> OR, or
        // the negated single-expr variant) would wrap it regardless.
        $this->assertIsArray($entity->comments);
        $this->assertNotInstanceOf(Collection::class, $entity->comments);
        $this->assertCount(1, $entity->comments);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLazyMorphManyBecomesLazyCollectionNotProxy(): void
    {
        [$hydrator, $loader, $em] = $this->buildHydrator();

        $loader->expects($this->never())->method('load');
        $em->expects($this->never())->method('getReference');
        $em->expects($this->never())->method('createLazyReference');

        /** @var MorphMutationLazyOwner $entity */
        $entity = $hydrator->hydrate(MorphMutationLazyOwner::class, [
            'id' => 1,
            'name' => 'Owner',
        ]);

        // isCollectionRelation must be true for MorphMany so the lazy branch
        // wraps it in a LazyCollection, not a proxy via the inverse-single-entity path.
        $this->assertInstanceOf(LazyCollection::class, $entity->comments);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLazyMorphManyCountLoaderIsNullSoFullLoadIsUsedForCount(): void
    {
        [$hydrator, $loader] = $this->buildHydrator();

        $comment = new MorphMutationComment();
        $comment->id = 1;

        // count() must never be called for MorphMany (no COUNT optimisation) —
        // the LogicalOrSingleSubExprNegation mutant would wrongly assign a countLoader.
        $loader->expects($this->never())->method('count');
        $loader->expects($this->once())->method('load')->willReturn([$comment]);

        /** @var MorphMutationLazyOwner $entity */
        $entity = $hydrator->hydrate(MorphMutationLazyOwner::class, [
            'id' => 1,
            'name' => 'Owner',
        ]);

        $this->assertSame(1, $entity->comments->count());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEntityManagerIsFetchedOnlyOnceAcrossMultipleLazyRelations(): void
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $em = $this->createMock(EntityManager::class);
        $loader = $this->createMock(RelationshipLoader::class);
        $loader->method('getMetadataRegistry')->willReturn($this->registry);

        // AssignCoalesce mutant (??= -> =) would call getEntityManager() once
        // per lazy relation (twice here); the real code calls it once total.
        $loader->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($em);

        $em->method('getReference')->willReturn($this->createStub(MorphMutationComment::class));

        $hydrator = new ObjectHydrator($unitOfWork, $loader);

        $hydrator->hydrate(DoubleLazyOwner::class, [
            'id' => 1,
            'name' => 'Owner',
            'first_comment_id' => 5,
            'second_comment_id' => 6,
        ]);
    }
}
