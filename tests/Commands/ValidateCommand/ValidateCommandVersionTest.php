<?php

namespace Articulate\Tests\Commands\ValidateCommand;

use Articulate\Commands\ValidateCommand;
use Articulate\Modules\Database\SchemaComparator\DatabaseSchemaComparator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ValidateCommandVersionTest extends TestCase {
    private const FIXTURES = __DIR__ . '/../../Schema/VersionGuard/Fixtures/';

    private const FIXTURE_NAMESPACE = 'Articulate\\Tests\\Schema\\VersionGuard\\Fixtures\\';

    /**
     * @param array<string, mixed> $input
     */
    private function runValidate(string $scenario, array $input = [], ?bool $lenientByDefault = null): CommandTester
    {
        $schemaComparator = $this->createStub(DatabaseSchemaComparator::class);
        $schemaComparator->method('compareAll')->willReturn([]);

        $paths = [self::FIXTURES . $scenario];
        $command = $lenientByDefault === null
            ? new ValidateCommand($schemaComparator, $paths)
            : new ValidateCommand($schemaComparator, $paths, lenientVersionChecks: $lenientByDefault);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function flatDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', '', $tester->getDisplay());
    }

    public function testFindingsFailTheCommandByDefault(): void
    {
        $tester = $this->runValidate('GuardedWriterUnacknowledged');

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('version-guard errors found', $tester->getDisplay());
    }

    public function testFindingMessageIsRenderedAsAnError(): void
    {
        $tester = $this->runValidate('GuardedWriterUnacknowledged');

        $this->assertStringContainsString(
            '[ERROR]Class"' . self::FIXTURE_NAMESPACE . 'GuardedWriterUnacknowledged\\Writer"'
            . 'persistsstatusguardedby#[Version]column"version"ontable"vg_guarded"'
            . 'withoutitsown#[Version]ora#[VersionAware([\'version\'])]acknowledgement.',
            $this->flatDisplay($tester),
        );
    }

    public function testLenientFlagDowngradesOnlyDowngradableFindingsToWarnings(): void
    {
        $tester = $this->runValidate('RivalCounters', ['--lenient' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $display = $this->flatDisplay($tester);
        $this->assertStringContainsString('[ERROR]Rivalcounters', $display);
        $this->assertStringContainsString('[WARNING]Class"' . self::FIXTURE_NAMESPACE . 'RivalCounters\\StatusSlice"persists', $display);
        $this->assertStringContainsString('[WARNING]Class"' . self::FIXTURE_NAMESPACE . 'RivalCounters\\OtherSlice"persists', $display);
    }

    public function testLenientFlagWithOnlyDowngradedFindingsPassesWithCaution(): void
    {
        $tester = $this->runValidate('GuardedWriterUnacknowledged', ['--lenient' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('lenient mode', $tester->getDisplay());
    }

    public function testConstructorArgumentCanMakeLenientTheDefault(): void
    {
        $tester = $this->runValidate('GuardedWriterUnacknowledged', lenientByDefault: true);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('lenient mode', $tester->getDisplay());
    }
}
