<?php

namespace Articulate\Tests\Commands\ValidateCommand;

use Articulate\Commands\ValidateCommand;
use Articulate\Modules\Database\SchemaComparator\DatabaseSchemaComparator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ValidateCommandVersionTest extends TestCase {
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/articulate_validate_version_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function uniqueNamespace(): string
    {
        return 'Articulate\\Tests\\Generated\\ValidateVersion' . str_replace('.', '', uniqid('', true));
    }

    private function writeFixture(string $fileName, string $namespace, string $body): void
    {
        $path = $this->tempDir . '/' . $fileName;
        file_put_contents($path, "<?php\n\nnamespace {$namespace};\n\n{$body}");
        require_once $path;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runValidate(bool $lenientByDefault = false, array $input = []): CommandTester
    {
        $schemaComparator = $this->createStub(DatabaseSchemaComparator::class);
        $schemaComparator->method('compareAll')->willReturn([]);

        $command = new ValidateCommand($schemaComparator, [$this->tempDir], lenientVersionChecks: $lenientByDefault);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    public function testCosmeticSliceWithNoVersionAttributeValidatesClean(): void
    {
        $ns = $this->uniqueNamespace();

        $this->writeFixture('Owner.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_clean')]
class Owner {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Version]
    public int $version = 0;

    #[\Articulate\Attributes\Property]
    public string $status = '';
}
PHP);

        $this->writeFixture('Cosmetic.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_clean')]
class Cosmetic {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property]
    public string $alias = '';
}
PHP);

        $tester = $this->runValidate();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Schema is valid', $tester->getDisplay());
    }

    public function testSliceWritingGuardedColumnWithoutAcknowledgementErrors(): void
    {
        $ns = $this->uniqueNamespace();
        $this->writeGuardedPair($ns, acknowledge: false);

        $tester = $this->runValidate();

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('status', $display);
        $this->assertStringContainsString('#[Version] column "version"', $display);
        $this->assertStringContainsString('#[VersionAware', $display);
    }

    public function testVersionAwareNamingTheGuardClearsTheError(): void
    {
        $ns = $this->uniqueNamespace();
        $this->writeGuardedPair($ns, acknowledge: true);

        $tester = $this->runValidate();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Schema is valid', $tester->getDisplay());
    }

    public function testLenientCliFlagDowngradesMissingAcknowledgementToWarning(): void
    {
        $ns = $this->uniqueNamespace();
        $this->writeGuardedPair($ns, acknowledge: false);

        $tester = $this->runValidate(input: ['--lenient' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('lenient mode', $display);
        // the downgraded message body is still shown, as a warning
        $this->assertStringContainsString('status', $display);
        $this->assertStringContainsString('#[Version] column "version"', $display);
    }

    public function testStrictIsTheDefaultWhenNoConstructorArgumentIsGiven(): void
    {
        $ns = $this->uniqueNamespace();
        $this->writeGuardedPair($ns, acknowledge: false);

        $schemaComparator = $this->createStub(DatabaseSchemaComparator::class);
        $schemaComparator->method('compareAll')->willReturn([]);

        $command = new ValidateCommand($schemaComparator, [$this->tempDir]);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('version-guard errors found', $tester->getDisplay());
    }

    public function testConstructorArgumentCanMakeLenientTheDefault(): void
    {
        $ns = $this->uniqueNamespace();
        $this->writeGuardedPair($ns, acknowledge: false);

        $tester = $this->runValidate(lenientByDefault: true);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('lenient mode', $tester->getDisplay());
    }

    public function testSliceOwningItsOwnVersionOverTheColumnValidatesClean(): void
    {
        $ns = $this->uniqueNamespace();

        $this->writeFixture('A.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_own')]
class A {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Version]
    public int $version = 0;

    #[\Articulate\Attributes\Property]
    public string $status = '';
}
PHP);

        $this->writeFixture('B.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_own')]
class B {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Version]
    public int $version = 0;

    #[\Articulate\Attributes\Property]
    public string $status = '';

    #[\Articulate\Attributes\Property]
    public string $note = '';
}
PHP);

        $tester = $this->runValidate();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringNotContainsString('#[VersionAware', $tester->getDisplay());
    }

    public function testRivalCountersErrorIsUnaffectedByLenientFlag(): void
    {
        $ns = $this->uniqueNamespace();

        $this->writeFixture('StatusSlice.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_rivals')]
class StatusSlice {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property(name: 'a_version')]
    #[\Articulate\Attributes\Version]
    public int $aVersion = 0;

    #[\Articulate\Attributes\Property]
    public string $shared = '';
}
PHP);

        $this->writeFixture('OtherSlice.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_rivals')]
class OtherSlice {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property(name: 'b_version')]
    #[\Articulate\Attributes\Version]
    public int $bVersion = 0;

    #[\Articulate\Attributes\Property]
    public string $shared = '';
}
PHP);

        $strict = $this->runValidate();
        $this->assertSame(1, $strict->getStatusCode());
        $this->assertStringContainsString('Rival counters', $strict->getDisplay());

        $lenient = $this->runValidate(input: ['--lenient' => true]);
        $this->assertSame(1, $lenient->getStatusCode());
        $this->assertStringContainsString('Rival counters', $lenient->getDisplay());
    }

    public function testOldCoverageErrorAndMultiVersionInfoLineAreGone(): void
    {
        $ns = $this->uniqueNamespace();

        // Two disjoint #[Version] columns on one table — the old code emitted an
        // info line for this and a coverage error against each sibling.
        $this->writeFixture('Left.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_disjoint')]
class Left {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property(name: 'a_version')]
    #[\Articulate\Attributes\Version]
    public int $aVersion = 0;

    #[\Articulate\Attributes\Property]
    public string $colA = '';
}
PHP);

        $this->writeFixture('Right.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_disjoint')]
class Right {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property(name: 'b_version')]
    #[\Articulate\Attributes\Version]
    public int $bVersion = 0;

    #[\Articulate\Attributes\Property]
    public string $colB = '';
}
PHP);

        $tester = $this->runValidate();

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('does not account for version column', $display);
        $this->assertStringNotContainsString('multiple distinct #[Version] columns', $display);
    }

    public function testGuardSetIsTheUnionOfEverySliceDeclaringThatVersionColumn(): void
    {
        $ns = $this->uniqueNamespace();

        $this->writeFixture('GuardsColA.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_union')]
class GuardsColA {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Version]
    public int $version = 0;

    #[\Articulate\Attributes\Property]
    public string $colA = '';
}
PHP);

        $this->writeFixture('GuardsColB.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_union')]
class GuardsColB {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Version]
    public int $version = 0;

    #[\Articulate\Attributes\Property]
    public string $colB = '';
}
PHP);

        $this->writeFixture('WritesBoth.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_union')]
class WritesBoth {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property]
    public string $colA = '';

    #[\Articulate\Attributes\Property]
    public string $colB = '';
}
PHP);

        $tester = $this->runValidate();

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('col_a', $display);
        $this->assertStringContainsString('col_b', $display);
    }

    public function testAcknowledgingOneGuardStillLeavesTheOtherChecked(): void
    {
        $ns = $this->uniqueNamespace();

        $this->writeFixture('StatusOwner.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_two')]
class StatusOwner {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property(name: 'status_version')]
    #[\Articulate\Attributes\Version]
    public int $statusVersion = 0;

    #[\Articulate\Attributes\Property]
    public string $status = '';
}
PHP);

        $this->writeFixture('PriceOwner.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_two')]
class PriceOwner {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property(name: 'price_version')]
    #[\Articulate\Attributes\Version]
    public int $priceVersion = 0;

    #[\Articulate\Attributes\Property]
    public string $price = '';
}
PHP);

        // Acknowledges only status_version, but also writes the price-guarded column.
        $this->writeFixture('PartialWriter.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_two')]
#[\Articulate\Attributes\VersionAware(['status_version'])]
class PartialWriter {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Property]
    public string $status = '';

    #[\Articulate\Attributes\Property]
    public string $price = '';
}
PHP);

        $tester = $this->runValidate();

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('price_version', $display);
        $this->assertStringNotContainsString('#[Version] column "status_version"', $display);
    }

    private function writeGuardedPair(string $ns, bool $acknowledge): void
    {
        $this->writeFixture('Owner.php', $ns, <<<'PHP'
#[\Articulate\Attributes\Entity(tableName: 'vg_guarded')]
class Owner {
    #[\Articulate\Attributes\Indexes\PrimaryKey]
    public ?int $id = null;

    #[\Articulate\Attributes\Version]
    public int $version = 0;

    #[\Articulate\Attributes\Property]
    public string $status = '';
}
PHP);

        $awareAttr = $acknowledge ? "#[\\Articulate\\Attributes\\VersionAware(['version'])]\n" : '';

        $this->writeFixture('Writer.php', $ns, <<<PHP
#[\\Articulate\\Attributes\\Entity(tableName: 'vg_guarded')]
{$awareAttr}class Writer {
    #[\\Articulate\\Attributes\\Indexes\\PrimaryKey]
    public ?int \$id = null;

    #[\\Articulate\\Attributes\\Property]
    public string \$status = '';
}
PHP);
    }
}
