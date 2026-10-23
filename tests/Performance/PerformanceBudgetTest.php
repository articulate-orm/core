<?php

namespace Articulate\Tests\Performance;

use Articulate\Benchmarks\BenchDatabase;
use Articulate\Benchmarks\Fixtures\BenchAccount;
use Articulate\Benchmarks\Fixtures\BenchAccountLogin;
use Articulate\Benchmarks\Fixtures\BenchAccountProfile;
use Articulate\Modules\EntityManager\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic performance guards: SQL statement counts are exact, memory ceilings
 * carry generous headroom so they only trip on real regressions (leaks, N+1, copies).
 */
class PerformanceBudgetTest extends TestCase {
    private const ROWS = 2000;

    /** Measured ~780 B/entity; ceiling leaves ~2.5x headroom. */
    private const MAX_BYTES_PER_HYDRATED_ENTITY = 2000;

    /** Measured ~400 B retained after three load/clear rounds. */
    private const MAX_RETAINED_AFTER_CLEAR_BYTES = 20000;

    private BenchDatabase $database;

    private EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->database = new BenchDatabase();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->database->createAccounts(self::ROWS);
        $this->entityManager = new EntityManager($this->database->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->dropAccounts();
        }

        parent::tearDown();
    }

    public function testFindAllIsOneQuery(): void
    {
        $accounts = $this->entityManager->findAll(BenchAccount::class);

        $this->assertCount(self::ROWS, $accounts);
        $this->assertSame(1, $this->database->queries->total);
    }

    public function testFlushingNoChangesIssuesNoQueries(): void
    {
        $this->entityManager->findAll(BenchAccount::class);
        $this->database->queries->reset();

        $this->entityManager->flush();

        $this->assertSame(0, $this->database->queries->total);
    }

    public function testFlushIssuesOneUpdatePerDirtyEntity(): void
    {
        $accounts = $this->entityManager->findAll(BenchAccount::class);
        $this->database->queries->reset();

        foreach ($accounts as $account) {
            $account->loginCount++;
        }
        $this->entityManager->flush();

        $this->assertSame(self::ROWS, $this->database->queries->byVerb['UPDATE'] ?? 0);
    }

    public function testSiblingSlicesOnOneRowMergeIntoOneUpdate(): void
    {
        $login = $this->entityManager->find(BenchAccountLogin::class, 1);
        $profile = $this->entityManager->find(BenchAccountProfile::class, 1);
        $this->database->queries->reset();

        $login->loginCount++;
        $profile->bio = 'merged';
        $this->entityManager->flush();

        $this->assertSame(1, $this->database->queries->byVerb['UPDATE'] ?? 0);
    }

    public function testHydrationMemoryStaysWithinBudget(): void
    {
        $this->entityManager->findAll(BenchAccount::class);
        $this->entityManager->clear();
        gc_collect_cycles();

        $before = memory_get_usage();
        $this->entityManager->findAll(BenchAccount::class);
        $held = memory_get_usage() - $before;

        $this->assertLessThan(self::MAX_BYTES_PER_HYDRATED_ENTITY * self::ROWS, $held);
    }

    public function testClearReleasesHydratedEntities(): void
    {
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
}
