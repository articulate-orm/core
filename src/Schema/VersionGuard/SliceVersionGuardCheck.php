<?php

namespace Articulate\Schema\VersionGuard;

use Articulate\Schema\EntityMetadata;

/**
 * The per-slice version-guard rules of ADR-0001, applied across all slices of
 * each table:
 *
 *  - rival counters: two distinct version columns whose guard sets overlap;
 *  - plain version column: a slice maps a sibling's version column as a plain
 *    property and could overwrite the counter (an acknowledgement does not
 *    clear it);
 *  - unacknowledged guarded write: a slice persists a column in a sibling's
 *    guard set without its own #[Version] over that version column or a
 *    #[VersionAware] acknowledgement of it.
 *
 * Findings come per table in discovery order: rival counters first, then each
 * slice's plain-version-column findings before its unacknowledged writes.
 */
final class SliceVersionGuardCheck {
    /**
     * @return list<VersionGuardFinding>
     */
    public function check(EntityMetadata ...$slices): array
    {
        $slicesByTable = [];
        foreach ($slices as $slice) {
            $slicesByTable[$slice->getTableName()][] = $slice;
        }

        $findings = [];
        foreach ($slicesByTable as $table => $siblings) {
            array_push($findings, ...$this->checkTable($table, $siblings));
        }

        return $findings;
    }

    /**
     * @param list<EntityMetadata> $siblings
     * @return list<VersionGuardFinding>
     */
    private function checkTable(string $table, array $siblings): array
    {
        $guardSets = $this->guardSets($siblings);
        $findings = $this->rivalCounters($table, $guardSets);
        foreach ($siblings as $slice) {
            array_push($findings, ...$this->plainVersionColumns($table, $slice, $guardSets));
            array_push($findings, ...$this->unacknowledgedGuardedWrites($table, $slice, $guardSets));
        }

        return $findings;
    }

    /**
     * A version column's guard set is the union over every slice declaring it,
     * so splitting the declaration across slices cannot shrink it.
     *
     * @param list<EntityMetadata> $siblings
     * @return array<string, list<string>> version column => guard set
     */
    private function guardSets(array $siblings): array
    {
        $guardSets = [];
        foreach ($siblings as $slice) {
            foreach ($slice->getVersionColumns() as $versionColumn) {
                $guardSets[$versionColumn] = array_values(array_unique(array_merge(
                    $guardSets[$versionColumn] ?? [],
                    $slice->getGuardSet(),
                )));
            }
        }

        return $guardSets;
    }

    /**
     * @param array<string, list<string>> $guardSets
     * @return list<VersionGuardFinding>
     */
    private function rivalCounters(string $table, array $guardSets): array
    {
        $findings = [];
        $versionColumns = array_keys($guardSets);
        foreach ($versionColumns as $i => $columnA) {
            foreach (array_slice($versionColumns, $i + 1) as $columnB) {
                $overlap = array_values(array_intersect($guardSets[$columnA], $guardSets[$columnB]));
                if ($overlap === []) {
                    continue;
                }

                $findings[] = new VersionGuardFinding(
                    VersionGuardViolation::RIVAL_COUNTERS,
                    $table,
                    null,
                    [$columnA, $columnB],
                    $overlap,
                );
            }
        }

        return $findings;
    }

    /**
     * Only the slice declaring a version column via #[Version] gets the bump/check
     * and the ManagedVersionColumnException guard; a sibling mapping it as a plain
     * property bypasses both.
     *
     * @param array<string, list<string>> $guardSets
     * @return list<VersionGuardFinding>
     */
    private function plainVersionColumns(string $table, EntityMetadata $slice, array $guardSets): array
    {
        $findings = [];
        $plainlyMapped = array_diff(
            array_intersect($slice->getColumnNames(), array_keys($guardSets)),
            $slice->getVersionColumns(),
        );
        foreach ($plainlyMapped as $versionColumn) {
            $findings[] = new VersionGuardFinding(
                VersionGuardViolation::PLAIN_VERSION_COLUMN,
                $table,
                $slice->getClassName(),
                [$versionColumn],
                [$versionColumn],
            );
        }

        return $findings;
    }

    /**
     * @param array<string, list<string>> $guardSets
     * @return list<VersionGuardFinding>
     */
    private function unacknowledgedGuardedWrites(string $table, EntityMetadata $slice, array $guardSets): array
    {
        $findings = [];
        $exempt = array_merge($slice->getVersionColumns(), $slice->getAcknowledgedVersionColumns());
        foreach ($guardSets as $versionColumn => $guardSet) {
            if (in_array($versionColumn, $exempt, true)) {
                continue;
            }

            $written = array_values(array_intersect($slice->getGuardSet(), $guardSet));
            if ($written === []) {
                continue;
            }

            $findings[] = new VersionGuardFinding(
                VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE,
                $table,
                $slice->getClassName(),
                [$versionColumn],
                $written,
            );
        }

        return $findings;
    }
}
