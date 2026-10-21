<?php

namespace Articulate\Commands;

use Articulate\Attributes\Reflection\ReflectionEntity;
use Articulate\Modules\Database\SchemaComparator\DatabaseSchemaComparator;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\VersionGuard\SliceVersionGuardCheck;
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
        private readonly SliceVersionGuardCheck $sliceVersionGuardCheck = new SliceVersionGuardCheck(),
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
        $versionResult = $this->reportVersionGuardFindings($entityClasses, $io, $lenient);
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
     * @param ReflectionEntity[] $entityClasses
     * @return array{errors: int, warnings: int}
     */
    private function reportVersionGuardFindings(array $entityClasses, SymfonyStyle $io, bool $lenient): array
    {
        $slices = [];
        foreach ($entityClasses as $reflectionEntity) {
            $slices[] = $this->metadataRegistry->getMetadata($reflectionEntity->getName());
        }

        $errors = 0;
        $warnings = 0;
        foreach ($this->sliceVersionGuardCheck->check(...$slices) as $finding) {
            if ($lenient && $finding->kind->downgradable()) {
                $warnings++;
                $io->warning($finding->message());

                continue;
            }

            $errors++;
            $io->error($finding->message());
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
