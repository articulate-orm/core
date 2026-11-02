<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\OwnVersionOverSameColumn;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_own')]
class A {
    #[PrimaryKey]
    public ?int $id = null;

    #[Version]
    public int $version = 0;

    #[Property]
    public string $status = '';
}
