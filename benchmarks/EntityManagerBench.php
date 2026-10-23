<?php

namespace Articulate\Benchmarks;

use Articulate\Benchmarks\Fixtures\BenchAccount;
use Articulate\Benchmarks\Fixtures\BenchAccountLogin;
use Articulate\Benchmarks\Fixtures\BenchAccountProfile;
use Articulate\Modules\EntityManager\EntityManager;
use PhpBench\Attributes as Bench;

#[Bench\ParamProviders('provideRowCounts')]
#[Bench\AfterMethods('tearDown')]
#[Bench\Iterations(3)]
#[Bench\Revs(1)]
#[Bench\Warmup(0)]
class EntityManagerBench {
    private int $rows = 0;

    private BenchDatabase $database;

    private EntityManager $entityManager;

    /**
     * @return iterable<string, array{rows: int}>
     */
    public function provideRowCounts(): iterable
    {
        yield '2k' => ['rows' => 2000];
        yield '10k' => ['rows' => 10000];
    }

    /**
     * @param array{rows: int} $params
     */
    public function setUp(array $params): void
    {
        $this->rows = $params['rows'];
        $this->database = new BenchDatabase();
        $this->database->createAccounts($this->rows);
        $this->entityManager = new EntityManager($this->database->connection);
    }

    public function tearDown(): void
    {
        $this->database->dropAccounts();
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchHydrateAll(): void
    {
        $this->entityManager->findAll(BenchAccount::class);
        $this->entityManager->clear();
    }

    /**
     * @param array{rows: int} $params
     */
    public function loadAccountsAndDirty(array $params): void
    {
        $this->setUp($params);
        foreach ($this->entityManager->findAll(BenchAccount::class) as $account) {
            ++$account->loginCount;
            $account->bio = 'updated';
        }
    }

    /**
     * @param array{rows: int} $params
     */
    public function loadSlicesAndDirty(array $params): void
    {
        $this->setUp($params);
        foreach ($this->entityManager->findAll(BenchAccountLogin::class) as $login) {
            ++$login->loginCount;
        }
        foreach ($this->entityManager->findAll(BenchAccountProfile::class) as $profile) {
            $profile->bio = 'updated';
        }
    }

    /**
     * Times flush only: hydration and dirtying happen in the before-method.
     * One entity, two changed columns, one UPDATE per row.
     */
    #[Bench\BeforeMethods('loadAccountsAndDirty')]
    public function benchFlushUpdates(): void
    {
        $this->entityManager->flush();
    }

    /**
     * Same row count and same two changed columns as benchFlushUpdates, but spread over two
     * sibling slices of each row. Both end up as one UPDATE per row, so the time difference
     * to benchFlushUpdates is the cost of detecting and merging siblings.
     */
    #[Bench\BeforeMethods('loadSlicesAndDirty')]
    public function benchFlushMergedSlices(): void
    {
        $this->entityManager->flush();
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchFlushInserts(): void
    {
        for ($i = 0; $i < $this->rows; ++$i) {
            $account = new BenchAccount();
            $account->email = "new{$i}@example.com";
            $account->displayName = "New {$i}";
            $account->bio = 'bio';
            $this->entityManager->persist($account);
        }
        $this->entityManager->flushAndClear();
    }
}
