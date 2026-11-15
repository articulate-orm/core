<?php

namespace Articulate\Schema\VersionGuard;

enum VersionGuardViolation {
    case RIVAL_COUNTERS;
    case UNACKNOWLEDGED_GUARDED_WRITE;
    case PLAIN_VERSION_COLUMN;

    /**
     * Only a missing acknowledgement may be downgraded to a warning. Rival counters
     * (ADR-0001) and a plain version column (PR #48) are real lost-update risks
     * and stay errors whatever the caller's leniency.
     */
    public function downgradable(): bool
    {
        return $this === self::UNACKNOWLEDGED_GUARDED_WRITE;
    }
}
