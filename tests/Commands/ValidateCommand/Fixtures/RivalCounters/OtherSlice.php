<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\RivalCounters;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_rivals')]
class OtherSlice {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'b_version')]
    #[Version]
    public int $bVersion = 0;

    #[Property]
    public string $shared = '';
}
