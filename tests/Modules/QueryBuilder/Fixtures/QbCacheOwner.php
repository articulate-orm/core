<?php

namespace Articulate\Tests\Modules\QueryBuilder\Fixtures;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'qb_cache_owners')]
class QbCacheOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}
