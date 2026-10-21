<?php

namespace Articulate\Exceptions;

class UpdateConflictException extends ArticulateException {
    public static function malformedStrategyOutput(string $strategyClass): self
    {
        return new self("{$strategyClass}::resolve() returned an update matching neither the entity-update nor the merged-update shape.");
    }
}
