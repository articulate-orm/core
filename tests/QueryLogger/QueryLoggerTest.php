<?php

namespace Articulate\Tests\QueryLogger;

use Articulate\QueryLogger\FileQueryLogger;
use Articulate\QueryLogger\PsrQueryLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class QueryLoggerTest extends TestCase {
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempFile = tempnam(sys_get_temp_dir(), 'query_log_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    public function testFileQueryLoggerWritesToFile(): void
    {
        $logger = new FileQueryLogger($this->tempFile);

        $logger->log(sql: 'SELECT * FROM users WHERE id = ?', parameters: [1], durationMs: 1.23);

        $contents = file_get_contents($this->tempFile);
        $this->assertStringContainsString('SELECT * FROM users WHERE id = ?', $contents);
        $this->assertStringContainsString('1.23 ms', $contents);
        $this->assertStringContainsString('[1]', $contents);
    }

    public function testFileQueryLoggerAppendsMultipleEntries(): void
    {
        $logger = new FileQueryLogger($this->tempFile);

        $logger->log(sql: 'SELECT 1', parameters: [], durationMs: 0.5);
        $logger->log(sql: 'SELECT 2', parameters: [], durationMs: 0.7);

        $contents = file_get_contents($this->tempFile);
        $this->assertStringContainsString('SELECT 1', $contents);
        $this->assertStringContainsString('SELECT 2', $contents);
    }

    public function testPsrQueryLoggerDelegatesToPsrLogger(): void
    {
        $spyLogger = new class() extends AbstractLogger {
            /** @var array{string, mixed[]}[] */
            public array $logs = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $queryLogger = new PsrQueryLogger($spyLogger);
        $queryLogger->log(sql: 'INSERT INTO users VALUES (?)', parameters: ['name'], durationMs: 2.5);

        $this->assertCount(1, $spyLogger->logs);
        $this->assertEquals('debug', $spyLogger->logs[0]['level']);
        $this->assertEquals('SQL query executed', $spyLogger->logs[0]['message']);
        $this->assertEquals('INSERT INTO users VALUES (?)', $spyLogger->logs[0]['context']['sql']);
        $this->assertEquals(['name'], $spyLogger->logs[0]['context']['parameters']);
        $this->assertEquals(2.5, $spyLogger->logs[0]['context']['duration_ms']);
    }

    public function testFileQueryLoggerPutsSpaceBeforeJsonParamsNotAfter(): void
    {
        // Covers Concat (operand swap) and ConcatOperandRemoval mutants on
        // $paramsJson = ' ' . json_encode($parameters): the space must separate
        // the duration suffix from the JSON, not trail after it, and must not
        // be dropped entirely.
        $logger = new FileQueryLogger($this->tempFile);

        $logger->log(sql: 'SELECT 1', parameters: ['a' => 1], durationMs: 0.1);

        $contents = file_get_contents($this->tempFile);
        $this->assertStringContainsString('ms {"a":1}', $contents);
        $this->assertStringNotContainsString('{"a":1} ms', $contents);
        $this->assertStringNotContainsString('ms{"a":1}', $contents);
    }

    public function testFileQueryLoggerOmitsParamsSuffixEntirelyWhenNoParameters(): void
    {
        $logger = new FileQueryLogger($this->tempFile);

        $logger->log(sql: 'SELECT 1', parameters: [], durationMs: 0.1);

        $contents = file_get_contents($this->tempFile);
        $this->assertMatchesRegularExpression('/ms\\nSELECT 1/', $contents);
    }
}
