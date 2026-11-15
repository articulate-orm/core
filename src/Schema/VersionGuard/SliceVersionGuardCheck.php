<?php

namespace Articulate\Schema\VersionGuard;

use Articulate\Schema\EntityMetadata;

/**
 * Finding order is a CLI contract (scripts grep validate output): per table in
 * discovery order, rival counters first, then each slice's plain-version-column
 * findings before its unacknowledged writes.
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
        foreach ($slicesByTable as $table => $tableSlices) {
            array_push($findings, ...$this->checkTable($table, $tableSlices));
        }

        return $findings;
    }

    /**
     * @param list<EntityMetadata> $tableSlices
     * @return list<VersionGuardFinding>
     */
    private function checkTable(string $table, array $tableSlices): array
    {
        $guardSets = $this->guardSets($tableSlices);
        $findings = $this->rivalCounters($table, $guardSets);
        foreach ($tableSlices as $slice) {
            array_push($findings, ...$this->plainVersionColumns($table, $slice, $guardSets));
            array_push($findings, ...$this->unacknowledgedGuardedWrites($table, $slice, $guardSets));
        }

        return $findings;
    }

    /**
     * A version column's guard set is the union over every slice declaring it,
     * so splitting the declaration across slices cannot shrink it.
     *
     * @param list<EntityMetadata> $tableSlices
     * @return array<string, array<int, string>> version column => guard set
     */
    private function guardSets(array $tableSlices): array
    {
        $guardSets = [];
        foreach ($tableSlices as $slice) {
            foreach ($slice->getVersionColumns() as $versionColumn) {
                $guardSets[$versionColumn] = array_unique(array_merge(
                    $guardSets[$versionColumn] ?? [],
                    $slice->getGuardSet(),
                ));
            }
        }

        return $guardSets;
    }

    /**
     * @param array<string, array<int, string>> $guardSets
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
     * @param array<string, array<int, string>> $guardSets
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
     * @param array<string, array<int, string>> $guardSets
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
