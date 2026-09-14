<?php

namespace Articulate\Commands;

use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Modules\Database\SchemaComparator\DatabaseSchemaComparator;
use Articulate\Schema\EntityMetadataRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'articulate:validate')]
class ValidateCommand extends Command {
    /**
     * @param array<int, string>|null $entitiesPath
     */
    public function __construct(
        private readonly DatabaseSchemaComparator $databaseSchemaComparator,
        private readonly ?array $entitiesPath = null,
        private readonly EntityClassDiscovery $entityClassDiscovery = new EntityClassDiscovery(),
        private readonly EntityMetadataRegistry $metadataRegistry = new EntityMetadataRegistry(),
        private readonly bool $lenientVersionChecks = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Validate that entity mappings are in sync with the database schema.');
        $this->addOption(
            'lenient',
            null,
            InputOption::VALUE_NONE,
            'Downgrade the missing-#[VersionAware]-acknowledgement error to a warning (rival-counters errors are unaffected).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entityClasses = $this->entityClassDiscovery->discover($this->entitiesPath);

        $compareResults = $this->databaseSchemaComparator->compareAll($entityClasses);

        $hasDrift = false;
        $allWarnings = [];

        foreach ($compareResults as $compareResult) {
            $hasDrift = true;
            $allWarnings = array_merge($allWarnings, $compareResult->warnings);
            $io->text(sprintf('[%s] Table "%s" needs %s.', strtoupper($compareResult->operation), $compareResult->name, $compareResult->operation));
        }

        foreach ($allWarnings as $warning) {
            $io->warning($warning);
        }

        $lenient = $this->lenientVersionChecks || $input->getOption('lenient');
        $versionResult = $this->validateVersionColumns($entityClasses, $io, $lenient);
        $hasVersionErrors = $versionResult['errors'] > 0;
        $hasVersionWarnings = $versionResult['warnings'] > 0;

        if (!$hasDrift && empty($allWarnings) && !$hasVersionErrors) {
            if ($hasVersionWarnings) {
                $io->caution('Optimistic-locking acknowledgement warnings found (lenient mode); see above.');
            } else {
                $io->success('Schema is valid. All entities are in sync with the database.');
            }

            return Command::SUCCESS;
        }

        if (!$hasDrift) {
            $io->caution('Schema is in sync but unmapped required columns were detected (see warnings above).');
        } else {
            $io->error('Schema is out of sync. Run articulate:diff to generate a migration.');
        }

        if ($hasVersionErrors) {
            $io->error('Optimistic-locking version-guard errors found (see above).');
        }

        return Command::FAILURE;
    }

    /**
     * Per-slice version-guard validation. Group entity classes by table; for
     * each #[Version] column compute its owning slice's guard set, then:
     *
     *  - error when a slice persists a column that is in another slice's guard
     *    set and the slice has neither its own #[Version] covering that column
     *    nor a #[VersionAware] naming that version column (downgraded to a
     *    warning when $lenient is set);
     *  - error ("rival counters") when two distinct #[Version] columns have
     *    overlapping guard sets (never downgraded).
     *
     * @param ReflectionEntity[] $entityClasses
     * @return array{errors: int, warnings: int}
     */
    private function validateVersionColumns(array $entityClasses, SymfonyStyle $io, bool $lenient): array
    {
        $errors = 0;
        $warnings = 0;
        $metadataByTable = [];

        foreach ($entityClasses as $reflectionEntity) {
            $metadata = $this->metadataRegistry->getMetadata($reflectionEntity->getName());
            $metadataByTable[$metadata->getTableName()][] = $metadata;
        }

        foreach ($metadataByTable as $tableName => $metadataGroup) {
            // version column => union of its owning slices' guard sets
            $guardSets = [];
            foreach ($metadataGroup as $metadata) {
                foreach ($metadata->getVersionColumns() as $versionColumn) {
                    $guardSets[$versionColumn] = array_values(array_unique(array_merge(
                        $guardSets[$versionColumn] ?? [],
                        $metadata->getGuardSet(),
                    )));
                }
            }

            if ($guardSets === []) {
                continue;
            }

            $versionColumns = array_keys($guardSets);
            foreach ($versionColumns as $i => $columnA) {
                foreach (array_slice($versionColumns, $i + 1) as $columnB) {
                    $overlap = array_values(array_intersect($guardSets[$columnA], $guardSets[$columnB]));
                    if ($overlap === []) {
                        continue;
                    }

                    $errors++;
                    $io->error(sprintf(
                        'Rival counters on table "%s": #[Version] columns "%s" and "%s" both guard %s.',
                        $tableName,
                        $columnA,
                        $columnB,
                        implode(', ', $overlap),
                    ));
                }
            }

            foreach ($metadataGroup as $metadata) {
                $ownVersionColumns = $metadata->getVersionColumns();
                $acknowledged = $metadata->getAcknowledgedVersionColumns();

                foreach ($guardSets as $versionColumn => $guardedColumns) {
                    if (in_array($versionColumn, $ownVersionColumns, true)) {
                        continue;
                    }

                    $written = array_values(array_intersect($metadata->getGuardSet(), $guardedColumns));
                    if ($written === [] || in_array($versionColumn, $acknowledged, true)) {
                        continue;
                    }

                    $message = sprintf(
                        'Class "%s" persists %s guarded by #[Version] column "%s" on table "%s" '
                        . 'without its own #[Version] or a #[VersionAware([\'%s\'])] acknowledgement.',
                        $metadata->getClassName(),
                        implode(', ', $written),
                        $versionColumn,
                        $tableName,
                        $versionColumn,
                    );

                    if ($lenient) {
                        $warnings++;
                        $io->warning($message);
                    } else {
                        $errors++;
                        $io->error($message);
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
