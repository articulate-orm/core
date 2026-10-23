<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\GuardSetUnion;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'vg_union')]
class WritesBoth {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $colA = '';

    #[Property]
    public string $colB = '';
}
