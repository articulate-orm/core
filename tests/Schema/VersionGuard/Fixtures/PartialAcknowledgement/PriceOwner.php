<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\PartialAcknowledgement;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_two')]
class PriceOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'price_version')]
    #[Version]
    public int $priceVersion = 0;

    #[Property]
    public string $price = '';
}
