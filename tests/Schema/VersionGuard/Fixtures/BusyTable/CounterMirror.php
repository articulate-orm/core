<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\BusyTable;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'vg_busy')]
class CounterMirror {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'a_version')]
    public int $aVersion = 0;

    #[Property(name: 'b_version')]
    public int $bVersion = 0;
}
