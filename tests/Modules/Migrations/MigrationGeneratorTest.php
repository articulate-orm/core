<?php

namespace Articulate\Tests\Modules\Migrations;

use Articulate\Modules\Migrations\Generator\MigrationGenerator;
use Articulate\Tests\AbstractTestCase;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationGeneratorTest extends AbstractTestCase {
    private string $tempDir;

    private MigrationGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/articulate_test_migrations_' . uniqid();
        $this->generator = new MigrationGenerator($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function testConstructorSetsOutputDirectory(): void
    {
        $outputDir = '/custom/output/dir';
        $generator = new MigrationGenerator($outputDir);

        $this->assertEquals($outputDir, $generator->getOutputDirectory());
    }

    public function testGetOutputDirectoryReturnsConfiguredDirectory(): void
    {
        $this->assertEquals($this->tempDir, $this->generator->getOutputDirectory());
    }

    public function testGenerateCreatesMigrationFileWithCorrectContent(): void
    {
        $namespace = 'App\\Migrations';
        $className = 'CreateUsersTable';
        $upScript = 'CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY);';
        $downScript = 'DROP TABLE users;';

        $this->generator->generate($namespace, $className, $upScript, $downScript);

        // Check that directory structure was created
        $expectedYear = date('Y');
        $expectedMonth = date('m');
        $expectedDir = $this->tempDir . '/' . $expectedYear . '/' . $expectedMonth;
        $expectedFile = $expectedDir . '/' . $className . '.php';

        $this->assertDirectoryExists($expectedDir);
        $this->assertFileExists($expectedFile);

        // Check file content
        $content = file_get_contents($expectedFile);

        // Verify namespace replacement
        $this->assertStringContainsString('namespace App\Migrations;', $content);

        // Verify class name replacement
        $this->assertStringContainsString('class CreateUsersTable extends BaseMigration', $content);

        // Verify up script replacement
        $this->assertStringContainsString($upScript, $content);

        // Verify down script replacement
        $this->assertStringContainsString($downScript, $content);
    }

    public function testGenerateCreatesYearMonthDirectories(): void
    {
        $namespace = 'TestNamespace';
        $className = 'TestMigration';
        $upScript = 'SELECT 1;';
        $downScript = 'SELECT 1;';

        $this->generator->generate($namespace, $className, $upScript, $downScript);

        $yearDir = $this->tempDir . '/' . date('Y');
        $monthDir = $yearDir . '/' . date('m');

        $this->assertDirectoryExists($yearDir);
        $this->assertDirectoryExists($monthDir);
    }

    public function testGenerateWithOutputInterfaceWritesMessage(): void
    {
        $output = $this->createMock(OutputInterface::class);

        $tempDir = sys_get_temp_dir() . '/articulate_test_migrations_output_' . uniqid();
        $generator = new MigrationGenerator($tempDir, $output);

        $namespace = 'TestNamespace';
        $className = 'TestMigrationWithOutput';
        $upScript = 'SELECT 1;';
        $downScript = 'SELECT 1;';

        $expectedFilePath = $tempDir . '/' . date('Y') . '/' . date('m') . '/TestMigrationWithOutput.php';

        $output->expects($this->once())
            ->method('writeln')
            ->with("Migration TestMigrationWithOutput generated successfully at $expectedFilePath");

        $generator->generate($namespace, $className, $upScript, $downScript);

        // Cleanup
        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }
    }

    public function testGenerateHandlesSpecialCharactersInScripts(): void
    {
        $namespace = 'TestNamespace';
        $className = 'SpecialCharsMigration';
        $upScript = '$this->addSql("CREATE TABLE test (id INT, name VARCHAR(255), data JSON)");';
        $downScript = '$this->addSql("DROP TABLE IF EXISTS test");';

        $this->generator->generate($namespace, $className, $upScript, $downScript);

        $expectedFile = $this->tempDir . '/' . date('Y') . '/' . date('m') . '/' . $className . '.php';
        $content = file_get_contents($expectedFile);

        $this->assertStringContainsString($upScript, $content);
        $this->assertStringContainsString($downScript, $content);
    }

    public function testGenerateOverwritesExistingFile(): void
    {
        $namespace = 'TestNamespace';
        $className = 'OverwriteTestMigration';
        $upScript1 = 'CREATE TABLE test1 (id INT);';
        $upScript2 = 'CREATE TABLE test2 (id INT);';

        // Generate first version
        $this->generator->generate($namespace, $className, $upScript1, 'DROP TABLE test1;');

        $filePath = $this->tempDir . '/' . date('Y') . '/' . date('m') . '/' . $className . '.php';
        $content1 = file_get_contents($filePath);
        $this->assertStringContainsString($upScript1, $content1);

        // Generate second version (should overwrite)
        $this->generator->generate($namespace, $className, $upScript2, 'DROP TABLE test2;');

        $content2 = file_get_contents($filePath);
        $this->assertStringContainsString($upScript2, $content2);
        $this->assertStringNotContainsString($upScript1, $content2);
    }

    public function testMultipleStatementsAreIndentedUniformly(): void
    {
        $upScript = implode(PHP_EOL, [
            '$this->addSql("CREATE TABLE users (id INT UNSIGNED NOT NULL)");',
            '$this->addSql("CREATE TABLE posts (id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL)");',
            '$this->addSql("ALTER TABLE posts ADD CONSTRAINT fk_posts_user_id FOREIGN KEY (user_id) REFERENCES users (id)");',
        ]);

        $this->generator->generate('TestNamespace', 'MultiStatementMigration', $upScript, '$this->addSql("DROP TABLE posts");');

        $file = $this->tempDir . '/' . date('Y') . '/' . date('m') . '/MultiStatementMigration.php';
        $content = file_get_contents($file);

        // Every statement line must be indented identically (8 spaces)
        $this->assertStringContainsString(
            '        $this->addSql("CREATE TABLE users',
            $content
        );
        $this->assertStringContainsString(
            '        $this->addSql("CREATE TABLE posts',
            $content
        );
        $this->assertStringContainsString(
            '        $this->addSql("ALTER TABLE posts',
            $content
        );

        // No line should start at column 0 with $this->
        $this->assertStringNotContainsString("\n\$this->addSql", $content);
    }

    public function testGenerateWithInvalidClassNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator->generate('TestNamespace', 'Invalid Class!', 'SELECT 1', 'SELECT 1');
    }

    public function testGenerateRejectsClassNameWithLeadingGarbageEvenIfSuffixValid(): void
    {
        // Covers PregMatchRemoveCaret: without the leading ^ anchor, a class
        // name with invalid leading characters followed by a valid identifier
        // suffix would incorrectly pass validation.
        $this->expectException(\InvalidArgumentException::class);
        $this->generator->generate('TestNamespace', '!!!ValidSuffix', 'SELECT 1', 'SELECT 1');
    }

    public function testGenerateCreatesDirectoryWithStandardPermissions(): void
    {
        // Covers DecrementInteger/IncrementInteger mutants on the mkdir() mode
        // argument (0755 -> 0754/0756 decimal variants).
        $this->generator->generate('TestNamespace', 'PermissionsMigration', 'SELECT 1;', 'SELECT 1;');

        $dir = $this->tempDir . '/' . date('Y') . '/' . date('m');
        $this->assertDirectoryExists($dir);

        $perms = fileperms($dir) & 0777;
        $this->assertSame(0755, $perms);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
