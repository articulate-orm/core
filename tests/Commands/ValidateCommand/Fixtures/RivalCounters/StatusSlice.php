<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\RivalCounters;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_rivals')]
class StatusSlice {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'a_version')]
    #[Version]
    public int $aVersion = 0;

    #[Property]
    public string $shared = '';
}
