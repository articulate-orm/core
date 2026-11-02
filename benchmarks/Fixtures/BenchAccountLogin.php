<?php

namespace Articulate\Benchmarks\Fixtures;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'bench_accounts')]
class BenchAccountLogin {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public int $loginCount = 0;
}
