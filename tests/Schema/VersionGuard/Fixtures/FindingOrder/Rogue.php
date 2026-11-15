<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\FindingOrder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'vg_order')]
class Rogue {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public int $version = 0;

    #[Property]
    public string $status = '';
}
