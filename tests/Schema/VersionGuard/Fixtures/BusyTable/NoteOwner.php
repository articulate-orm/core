<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\BusyTable;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_busy')]
class NoteOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'a_version')]
    #[Version]
    public int $aVersion = 0;

    #[Property]
    public string $note = '';

    #[Property]
    public string $shared = '';
}
