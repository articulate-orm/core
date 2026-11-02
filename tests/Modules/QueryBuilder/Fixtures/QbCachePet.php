<?php

namespace Articulate\Tests\Modules\QueryBuilder\Fixtures;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToOne;

#[Entity(tableName: 'qb_cache_pets')]
class QbCachePet {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;

    #[ManyToOne(targetEntity: QbCacheOwner::class, foreignKey: false)]
    public ?QbCacheOwner $owner = null;
}
