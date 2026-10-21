<?php

namespace Articulate\Modules\EntityManager;

use Closure;

/**
 * Outcome handle of a VersionedRowWrite: the DB row is already written, the
 * in-memory #[Version] properties are not yet touched.
 *
 * commit() moves them to the value the row now carries; abandon() puts them back
 * to the pre-flush value. Both write "original + fixed delta", so abandon() is a
 * safe no-op when commit() never ran.
 */
final readonly class VersionedRowWriteHandle {
    /**
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
