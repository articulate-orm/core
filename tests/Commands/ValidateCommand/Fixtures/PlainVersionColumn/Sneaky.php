<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\PlainVersionColumn;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\VersionAware;

#[Entity(tableName: 'vg_plain')]
#[VersionAware(['version'])]
class Sneaky {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public int $version = 0;
}
