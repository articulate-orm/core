<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\GuardedWriterUnacknowledged;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'vg_guarded')]
class Writer {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $status = '';
}
