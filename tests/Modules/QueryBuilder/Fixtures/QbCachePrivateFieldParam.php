<?php

namespace Articulate\Tests\Modules\QueryBuilder\Fixtures;

class QbCachePrivateFieldParam {
    public function __construct(private readonly int $value)
    {
    }

    public function value(): int
    {
        return $this->value;
    }
}
