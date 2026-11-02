<?php

namespace Articulate\Tests\Commands\ValidateCommand\Fixtures\PartialAcknowledgement;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\VersionAware;

#[Entity(tableName: 'vg_two')]
#[VersionAware(['status_version'])]
class PartialWriter {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $status = '';

    #[Property]
    public string $price = '';
}
