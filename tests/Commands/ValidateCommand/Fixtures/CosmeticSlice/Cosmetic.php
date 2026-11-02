<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\CosmeticSlice;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'vg_clean')]
class Cosmetic {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $alias = '';
}
