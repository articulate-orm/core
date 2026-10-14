<?php

namespace Articulate\Tests\Commands\ValidateCommand;

use Articulate\Commands\ValidateCommand;
use Articulate\Modules\Database\SchemaComparator\DatabaseSchemaComparator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ValidateCommandVersionTest extends TestCase {
    /**
     * @param array<string, mixed> $input
     */
    private function runValidate(string $scenario, bool $lenientByDefault = false, array $input = []): CommandTester
    {
        $schemaComparator = $this->createStub(DatabaseSchemaComparator::class);
        $schemaComparator->method('compareAll')->willReturn([]);

        $command = new ValidateCommand($schemaComparator, [__DIR__ . '/Fixtures/' . $scenario], lenientVersionChecks: $lenientByDefault);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    public function testCosmeticSliceWithNoVersionAttributeValidatesClean(): void
    {
        $tester = $this->runValidate('CosmeticSlice');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Schema is valid', $tester->getDisplay());
    }

    public function testSliceWritingGuardedColumnWithoutAcknowledgementErrors(): void
    {
        $tester = $this->runValidate('GuardedWriterUnacknowledged');

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('status', $display);
        $this->assertStringContainsString('#[Version] column "version"', $display);
        $this->assertStringContainsString('#[VersionAware', $display);
    }

    public function testVersionAwareNamingTheGuardClearsTheError(): void
    {
        $tester = $this->runValidate('GuardedWriterAcknowledged');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Schema is valid', $tester->getDisplay());
    }

    public function testLenientCliFlagDowngradesMissingAcknowledgementToWarning(): void
    {
        $tester = $this->runValidate('GuardedWriterUnacknowledged', input: ['--lenient' => true]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('lenient mode', $display);
        // the downgraded message body is still shown, as a warning
        $this->assertStringContainsString('status', $display);
        $this->assertStringContainsString('#[Version] column "version"', $display);
    }

    public function testStrictIsTheDefaultWhenNoConstructorArgumentIsGiven(): void
    {
        $schemaComparator = $this->createStub(DatabaseSchemaComparator::class);
        $schemaComparator->method('compareAll')->willReturn([]);

        $command = new ValidateCommand($schemaComparator, [__DIR__ . '/Fixtures/GuardedWriterUnacknowledged']);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('version-guard errors found', $tester->getDisplay());
    }

    public function testConstructorArgumentCanMakeLenientTheDefault(): void
    {
        $tester = $this->runValidate('GuardedWriterUnacknowledged', lenientByDefault: true);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('lenient mode', $tester->getDisplay());
    }

    public function testSiblingMappingVersionColumnAsPlainPropertyErrors(): void
    {
        $tester = $this->runValidate('PlainVersionColumn', input: ['--lenient' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $flat = preg_replace('/\s+/', '', $tester->getDisplay());
        $this->assertStringContainsString('Sneaky', $flat);
        $this->assertStringContainsString('asaplainproperty', $flat);
    }

    public function testSliceOwningItsOwnVersionOverTheColumnValidatesClean(): void
    {
        $tester = $this->runValidate('OwnVersionOverSameColumn');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringNotContainsString('#[VersionAware', $tester->getDisplay());
    }

    public function testRivalCountersErrorIsUnaffectedByLenientFlag(): void
    {
        $strict = $this->runValidate('RivalCounters');
        $this->assertSame(1, $strict->getStatusCode());
        $this->assertStringContainsString('Rival counters', $strict->getDisplay());

        $lenient = $this->runValidate('RivalCounters', input: ['--lenient' => true]);
        $this->assertSame(1, $lenient->getStatusCode());
        $this->assertStringContainsString('Rival counters', $lenient->getDisplay());
    }

    public function testOldCoverageErrorAndMultiVersionInfoLineAreGone(): void
    {
        $tester = $this->runValidate('DisjointVersions');

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('does not account for version column', $display);
        $this->assertStringNotContainsString('multiple distinct #[Version] columns', $display);
    }

    public function testGuardSetIsTheUnionOfEverySliceDeclaringThatVersionColumn(): void
    {
        $tester = $this->runValidate('GuardSetUnion');

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('col_a', $display);
        $this->assertStringContainsString('col_b', $display);
    }

    public function testAcknowledgingOneGuardStillLeavesTheOtherChecked(): void
    {
        $tester = $this->runValidate('PartialAcknowledgement');

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('price_version', $display);
        $this->assertStringNotContainsString('#[Version] column "status_version"', $display);
    }
}
