<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\GuardedWriterAcknowledged;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\VersionAware;

#[Entity(tableName: 'vg_guarded')]
#[VersionAware(['version'])]
class Writer {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $status = '';
}
