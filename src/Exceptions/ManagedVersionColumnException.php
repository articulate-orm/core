<?php

namespace Articulate\Exceptions;

/**
 * Thrown when a #[Version] column is assigned manually. The version column is
 * ORM-managed: it is bumped server-side (`version = version + 1`) and checked
 * in the UPDATE's WHERE clause against the value the ORM is tracking. A
 * hand-written value would only desync that check, so Articulate rejects it at
 * flush time rather than silently ignoring it.
 */
class ManagedVersionColumnException extends ArticulateException {
    public static function forColumn(string $entityClass, string $column): self
    {
        return new self(sprintf(
            'Column "%s" on "%s" is a #[Version] column managed by Articulate and cannot be '
            . 'assigned manually; the ORM bumps and checks it. Remove the manual assignment.',
            $column,
            $entityClass,
        ));
    }
}
