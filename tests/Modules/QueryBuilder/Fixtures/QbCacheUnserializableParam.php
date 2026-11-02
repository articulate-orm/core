<?php

namespace Articulate\Tests\Modules\QueryBuilder\Fixtures;

class QbCacheUnserializableParam {
    public \Closure $callback;

    public function __construct()
    {
        $this->callback = static fn (): int => 1;
    }
}
