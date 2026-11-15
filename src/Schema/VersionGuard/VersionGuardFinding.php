<?php

namespace Articulate\Schema\VersionGuard;

final readonly class VersionGuardFinding {
    /**
     * @param list<string> $versionColumns two for rival counters, one otherwise
     * @param list<string> $columns the overlapping, written or plainly mapped columns
     */
    public function __construct(
        public VersionGuardViolation $kind,
        public string $table,
        public ?string $sliceClass,
        public array $versionColumns,
        public array $columns,
    ) {
    }

    public function message(): string
    {
        return match ($this->kind) {
            VersionGuardViolation::RIVAL_COUNTERS => sprintf(
                'Rival counters on table "%s": #[Version] columns "%s" and "%s" both guard %s.',
                $this->table,
                $this->versionColumns[0],
                $this->versionColumns[1],
                implode(', ', $this->columns),
            ),
            VersionGuardViolation::PLAIN_VERSION_COLUMN => sprintf(
                'Class "%s" maps #[Version] column "%s" of table "%s" as a plain property; '
                . 'only a #[Version] property may map a version column.',
                $this->sliceClass,
                $this->versionColumns[0],
                $this->table,
            ),
            VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE => sprintf(
                'Class "%s" persists %s guarded by #[Version] column "%s" on table "%s" '
                . 'without its own #[Version] or a #[VersionAware([\'%s\'])] acknowledgement.',
                $this->sliceClass,
                implode(', ', $this->columns),
                $this->versionColumns[0],
                $this->table,
                $this->versionColumns[0],
            ),
        };
    }
}
