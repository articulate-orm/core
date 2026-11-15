<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\BusyTable;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_busy')]
class SharedOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'a_version')]
    #[Version]
    public int $aVersion = 0;

    #[Property]
    public string $shared = '';
}
