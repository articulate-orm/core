<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\BusyTable;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_busy')]
class BothOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'c_version')]
    #[Version]
    public int $cVersion = 0;

    #[Property]
    public string $shared = '';

    #[Property]
    public string $other = '';
}
