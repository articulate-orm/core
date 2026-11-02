<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\GuardSetUnion;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_union')]
class GuardsColB {
    #[PrimaryKey]
    public ?int $id = null;

    #[Version]
    public int $version = 0;

    #[Property]
    public string $colB = '';
}
