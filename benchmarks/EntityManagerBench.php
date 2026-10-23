<?php

namespace Articulate\Benchmarks;

use Articulate\Benchmarks\Fixtures\BenchAccount;
use Articulate\Benchmarks\Fixtures\BenchAccountLogin;
use Articulate\Benchmarks\Fixtures\BenchAccountProfile;
use Articulate\Modules\EntityManager\EntityManager;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods('setUp')]
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

    public function benchHydrateAll(): void
    {
        $this->entityManager->findAll(BenchAccount::class);
        $this->entityManager->clear();
    }

    public function benchFlushUpdates(): void
    {
        $accounts = $this->entityManager->findAll(BenchAccount::class);
        foreach ($accounts as $account) {
            ++$account->loginCount;
        }
        $this->entityManager->flushAndClear();
    }

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

    public function benchMergeSiblingSlices(): void
    {
        for ($id = 1; $id <= 500; ++$id) {
            $login = $this->entityManager->find(BenchAccountLogin::class, $id);
            $profile = $this->entityManager->find(BenchAccountProfile::class, $id);
            ++$login->loginCount;
            $profile->bio = "updated {$id}";
        }
        $this->entityManager->flushAndClear();
    }
}
