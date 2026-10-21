<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\BusyTable;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_busy')]
class OtherOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'b_version')]
    #[Version]
    public int $bVersion = 0;

    #[Property]
    public string $other = '';
}
