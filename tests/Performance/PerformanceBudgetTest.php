<?php

namespace Articulate\Tests\Performance;

use Articulate\Benchmarks\BenchDatabase;
use Articulate\Benchmarks\Fixtures\BenchAccount;
use Articulate\Benchmarks\Fixtures\BenchAccountLogin;
use Articulate\Benchmarks\Fixtures\BenchAccountProfile;
use Articulate\Modules\EntityManager\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic performance guards: SQL statement counts are exact, memory ceilings
 * carry generous headroom so they only trip on real regressions (leaks, N+1, copies).
 * Every budget runs at 2k and 10k rows, so a cost that stops scaling linearly shows up.
 */
class PerformanceBudgetTest extends TestCase {
    /** Measured ~820 B/entity; ceiling leaves ~2.4x headroom. */
    private const MAX_BYTES_PER_HYDRATED_ENTITY = 2000;

    /** Measured ~0 B retained after three load/clear rounds. */
    private const MAX_RETAINED_AFTER_CLEAR_BYTES = 20000;

    private BenchDatabase $database;

    private EntityManager $entityManager;

    /**
     * @return array<string, array{int}>
     */
    public static function rowCounts(): array
    {
        return ['2k rows' => [2000], '10k rows' => [10000]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->database = new BenchDatabase();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->dropAccounts();
        }

        parent::tearDown();
    }

    #[DataProvider('rowCounts')]
    public function testFindAllIsOneQuery(int $rows): void
    {
        $this->seed($rows);

        $accounts = $this->entityManager->findAll(BenchAccount::class);

        $this->assertCount($rows, $accounts);
        $this->assertSame(1, $this->database->queries->total);
    }

    #[DataProvider('rowCounts')]
    public function testFlushingNoChangesIssuesNoQueries(int $rows): void
    {
        $this->seed($rows);
        $this->entityManager->findAll(BenchAccount::class);
        $this->database->queries->reset();

        $this->entityManager->flush();

        $this->assertSame(0, $this->database->queries->total);
    }

    #[DataProvider('rowCounts')]
    public function testFlushIssuesOneUpdatePerDirtyEntity(int $rows): void
    {
        $this->seed($rows);
        $accounts = $this->entityManager->findAll(BenchAccount::class);
        $this->database->queries->reset();

        foreach ($accounts as $account) {
            $account->loginCount++;
        }
        $this->entityManager->flush();

        $this->assertSame($rows, $this->database->queries->byVerb['UPDATE'] ?? 0);
    }

    #[DataProvider('rowCounts')]
    public function testSiblingSlicesOnOneRowMergeIntoOneUpdate(int $rows): void
    {
        $this->seed($rows);
        $login = $this->entityManager->find(BenchAccountLogin::class, 1);
        $profile = $this->entityManager->find(BenchAccountProfile::class, 1);
        $this->database->queries->reset();

        $login->loginCount++;
        $profile->bio = 'merged';
        $this->entityManager->flush();

        $this->assertSame(1, $this->database->queries->byVerb['UPDATE'] ?? 0);
    }

    #[DataProvider('rowCounts')]
    public function testHydrationMemoryStaysWithinBudget(int $rows): void
    {
        $this->seed($rows);
        $this->entityManager->findAll(BenchAccount::class);
        $this->entityManager->clear();
        gc_collect_cycles();

        $before = memory_get_usage();
        $accounts = $this->entityManager->findAll(BenchAccount::class);
        $held = memory_get_usage() - $before;

        $this->assertCount($rows, $accounts);
        $this->assertLessThan(self::MAX_BYTES_PER_HYDRATED_ENTITY * $rows, $held);
    }

    #[DataProvider('rowCounts')]
    public function testClearReleasesHydratedEntities(int $rows): void
    {
        $this->seed($rows);
        $this->entityManager->findAll(BenchAccount::class);
        $this->entityManager->clear();
        gc_collect_cycles();
        $baseline = memory_get_usage();

        for ($round = 0; $round < 3; $round++) {
            $this->entityManager->findAll(BenchAccount::class);
            $this->entityManager->clear();
            gc_collect_cycles();
        }

        $this->assertLessThan(self::MAX_RETAINED_AFTER_CLEAR_BYTES, memory_get_usage() - $baseline);
    }

    private function seed(int $rows): void
    {
        $this->database->createAccounts($rows);
        $this->entityManager = new EntityManager($this->database->connection);
    }
}
