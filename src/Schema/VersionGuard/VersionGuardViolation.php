<?php

namespace Articulate\Schema\VersionGuard;

enum VersionGuardViolation: string {
    case RIVAL_COUNTERS = 'rival_counters';
    case UNACKNOWLEDGED_GUARDED_WRITE = 'unacknowledged_guarded_write';
    case PLAIN_VERSION_COLUMN = 'plain_version_column';

    /**
     * Only a missing acknowledgement may be downgraded to a warning. Rival counters
     * and a plain version column are real lost-update risks (ADR-0001) and stay
     * errors whatever the caller's leniency.
     */
    public function downgradable(): bool
    {
        return $this === self::UNACKNOWLEDGED_GUARDED_WRITE;
    }
}
