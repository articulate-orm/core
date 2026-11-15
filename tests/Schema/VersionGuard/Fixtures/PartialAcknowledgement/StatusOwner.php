<?php

namespace Articulate\Tests\Schema\VersionGuard\Fixtures\PartialAcknowledgement;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;

#[Entity(tableName: 'vg_two')]
class StatusOwner {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property(name: 'status_version')]
    #[Version]
    public int $statusVersion = 0;

    #[Property]
    public string $status = '';
}
