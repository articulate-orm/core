<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\PlainVersionColumn;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_plain')]
class Owner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Version]
    public int $version = 0;

    #[Property]
    public string $status = '';
}
