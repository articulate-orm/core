<?php

namespace Articulate\Tests\Modules\QueryBuilder\Fixtures;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'qb_cache_stock')]
class QbCacheCompositeStock {
    #[PrimaryKey]
    public int $warehouseId;

    #[PrimaryKey]
    public int $productId;

    #[Property]
    public int $quantity;
}
