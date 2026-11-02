<?php

namespace Articulate\Benchmarks\Fixtures;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;

#[Entity(tableName: 'bench_accounts')]
class BenchAccount {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $email = '';

    #[Property]
    public string $displayName = '';

    #[Property]
    public string $bio = '';

    #[Property]
    public int $loginCount = 0;
}
