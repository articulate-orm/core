<?php

namespace Articulate\Modules\EntityManager;

use Closure;

final readonly class VersionedRowWriteHandle {
    /**
     * Commit and abandon both write "original + fixed delta", so abandon() is a
     * safe no-op when commit() never ran.
     *
     * @param Closure(int $delta): void $shiftFromOriginal
     */
    public function __construct(private Closure $shiftFromOriginal)
    {
    }

    public function commit(): void
    {
        ($this->shiftFromOriginal)(1);
    }

    public function abandon(): void
    {
        ($this->shiftFromOriginal)(0);
    }
}
