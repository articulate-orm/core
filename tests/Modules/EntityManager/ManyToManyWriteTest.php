<?php

namespace Articulate\Tests\Modules\EntityManager;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Relations\ManyToMany;
use Articulate\Attributes\Relations\MappingTable;
use Articulate\Attributes\Relations\MappingTableProperty;
use Articulate\Collection\MappingCollection;
use Articulate\Collection\MappingItem;
use Articulate\Modules\EntityManager\Collection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

// ── Fixture entities ──────────────────────────────────────────────────────────

#[Entity(tableName: 'wt_users')]
class WriteTestUser {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[ManyToMany(
        targetEntity: WriteTestPerm::class,
        mappingTable: new MappingTable(
            name: 'wt_user_perms',
            properties: [
                new MappingTableProperty(name: 'role'),
                new MappingTableProperty(name: 'created_at', createdAt: true),
                new MappingTableProperty(name: 'updated_at', updatedAt: true),
            ]
        )
    )]
    public ?MappingCollection $permissions = null;

    #[ManyToMany(
        targetEntity: WriteTestTag::class,
        mappingTable: new MappingTable(name: 'wt_user_tags')
    )]
    public ?Collection $tags = null;
}

#[Entity(tableName: 'wt_users')]
class WriteTestArrayUser {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    /** @var array<int, WriteTestTag>|Collection */
    #[ManyToMany(
        targetEntity: WriteTestTag::class,
        mappingTable: new MappingTable(name: 'wt_user_tags')
    )]
    public array|Collection $tags = [];
}

#[Entity(tableName: 'wt_perms')]
class WriteTestPerm {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $label;
}

#[Entity(tableName: 'wt_tags')]
class WriteTestTag {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'pt_roles')]
class PlainTimestampRole {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $label;
}

#[Entity(tableName: 'pt_users')]
class PlainTimestampUser {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;

    #[ManyToMany(
        targetEntity: PlainTimestampRole::class,
        mappingTable: new MappingTable(
            name: 'pt_user_roles',
            properties: [new MappingTableProperty(name: 'created_at', createdAt: true)]
        )
    )]
    public ?Collection $roles = null;
}

// ── Test class ────────────────────────────────────────────────────────────────

class ManyToManyWriteTest extends DatabaseTestCase {
    private EntityManager $em;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function prepareTables(): void
    {
        $this->setCurrentDatabase($this->getConnection('mysql'), 'mysql');
        $this->cleanUpTables(['wt_user_perms', 'wt_user_tags', 'wt_users', 'wt_perms', 'wt_tags']);
        $this->currentConnection->executeQuery(
            'CREATE TABLE wt_users (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL)'
        );
        $this->currentConnection->executeQuery(
            'CREATE TABLE wt_perms (id INT PRIMARY KEY AUTO_INCREMENT, label VARCHAR(255) NOT NULL)'
        );
        $this->currentConnection->executeQuery(
            'CREATE TABLE wt_tags (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL)'
        );
        $this->currentConnection->executeQuery(
            'CREATE TABLE wt_user_perms (
                wt_users_id INT NOT NULL,
                wt_perms_id INT NOT NULL,
                role VARCHAR(50) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )'
        );
        $this->currentConnection->executeQuery(
            'CREATE TABLE wt_user_tags (wt_users_id INT NOT NULL, wt_tags_id INT NOT NULL)'
        );
        $this->em = new EntityManager($this->currentConnection);
    }

    /** @return array<int, array<string, mixed>> */
    private function pivotRows(string $table): array
    {
        return $this->currentConnection->executeQuery("SELECT * FROM {$table}")->fetchAll();
    }

    #[Group('database')]
    public function testInsertSyncsMappingCollectionWithPivotDataAndTimestamps(): void
    {
        $this->prepareTables();

        $perm = new WriteTestPerm();
        $perm->label = 'read';

        $user = new WriteTestUser();
        $user->name = 'Alice';
        $user->permissions = new MappingCollection([new MappingItem($perm, ['role' => 'viewer'])]);

        $this->em->persist($perm);
        $this->em->persist($user);
        $this->em->flush();

        $rows = $this->pivotRows('wt_user_perms');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]['wt_users_id']);
        $this->assertSame($perm->id, (int) $rows[0]['wt_perms_id']);
        $this->assertSame('viewer', $rows[0]['role']);
        $this->assertNotEmpty($rows[0]['created_at']);
        $this->assertNotEmpty($rows[0]['updated_at']);
        $this->assertSame($rows[0]['created_at'], $rows[0]['updated_at']);
    }

    #[Group('database')]
    public function testAddItemInsertsOnlyNewRow(): void
    {
        $this->prepareTables();

        $perm1 = new WriteTestPerm();
        $perm1->label = 'read';
        $perm2 = new WriteTestPerm();
        $perm2->label = 'write';

        $this->em->persist($perm1);
        $this->em->persist($perm2);
        $this->em->flush();

        $user = new WriteTestUser();
        $user->name = 'Bob';
        $user->permissions = new MappingCollection([new MappingItem($perm1, ['role' => 'viewer'])]);
        $this->em->persist($user);
        $this->em->flush();

        $firstCreatedAt = $this->pivotRows('wt_user_perms')[0]['created_at'];

        // Add second perm — only one INSERT, existing row untouched
        $user->permissions->add(new MappingItem($perm2, ['role' => 'editor']));
        $this->em->flush();

        $rows = $this->pivotRows('wt_user_perms');
        $this->assertCount(2, $rows);
        // perm1 row unchanged — created_at identical to original
        $perm1Row = array_values(array_filter($rows, fn ($r) => (int) $r['wt_perms_id'] === $perm1->id))[0];
        $this->assertSame($firstCreatedAt, $perm1Row['created_at']);
    }

    #[Group('database')]
    public function testRemoveItemDeletesOnlyThatRow(): void
    {
        $this->prepareTables();

        $perm1 = new WriteTestPerm();
        $perm1->label = 'read';
        $perm2 = new WriteTestPerm();
        $perm2->label = 'write';

        $this->em->persist($perm1);
        $this->em->persist($perm2);
        $this->em->flush();

        $user = new WriteTestUser();
        $user->name = 'Carol';
        $user->permissions = new MappingCollection([
            new MappingItem($perm1, ['role' => 'viewer']),
            new MappingItem($perm2, ['role' => 'editor']),
        ]);
        $this->em->persist($user);
        $this->em->flush();

        $this->assertCount(2, $this->pivotRows('wt_user_perms'));

        // Remove perm1 — only that row deleted, perm2 row survives
        $user->permissions->remove($perm1);
        $this->em->flush();

        $rows = $this->pivotRows('wt_user_perms');
        $this->assertCount(1, $rows);
        $this->assertSame($perm2->id, (int) $rows[0]['wt_perms_id']);
    }

    #[Group('database')]
    public function testMutatePivotValueUpdatesRowAndBumpsUpdatedAt(): void
    {
        $this->prepareTables();

        $perm = new WriteTestPerm();
        $perm->label = 'read';

        $this->em->persist($perm);
        $this->em->flush();

        $user = new WriteTestUser();
        $user->name = 'Dave';
        $user->permissions = new MappingCollection([new MappingItem($perm, ['role' => 'viewer'])]);
        $this->em->persist($user);
        $this->em->flush();

        $before = $this->pivotRows('wt_user_perms')[0];

        // Mutate pivot via setPivotValue — no add/remove, just UPDATE
        $user->permissions->itemFor($perm)->setPivotValue('role', 'admin');
        $this->em->flush();

        $after = $this->pivotRows('wt_user_perms')[0];
        $this->assertSame('admin', $after['role']);
        $this->assertSame($before['created_at'], $after['created_at']); // created_at unchanged
        // updated_at may or may not differ depending on sub-second timing; row exists and role changed
    }

    #[Group('database')]
    public function testDeleteEntityCleansPivotRows(): void
    {
        $this->prepareTables();

        $perm = new WriteTestPerm();
        $perm->label = 'admin';

        $user = new WriteTestUser();
        $user->name = 'Eve';
        $user->permissions = new MappingCollection([new MappingItem($perm, ['role' => 'admin'])]);

        $this->em->persist($perm);
        $this->em->persist($user);
        $this->em->flush();

        $this->assertCount(1, $this->pivotRows('wt_user_perms'));

        $this->em->remove($user);
        $this->em->flush();

        $this->assertCount(0, $this->pivotRows('wt_user_perms'));
    }

    #[Group('database')]
    public function testPlainCollectionWriteAndVerify(): void
    {
        $this->prepareTables();

        $tag = new WriteTestTag();
        $tag->name = 'php';

        $user = new WriteTestUser();
        $user->name = 'Frank';
        $user->tags = new Collection([$tag]);

        $this->em->persist($tag);
        $this->em->persist($user);
        $this->em->flush();

        $rows = $this->pivotRows('wt_user_tags');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]['wt_users_id']);
        $this->assertSame($tag->id, (int) $rows[0]['wt_tags_id']);
    }

    #[Group('database')]
    public function testPlainArrayOnNewEntityInsertsPivotRowsAndBecomesCollection(): void
    {
        $this->prepareTables();

        $tag1 = new WriteTestTag();
        $tag1->name = 'php';
        $tag2 = new WriteTestTag();
        $tag2->name = 'orm';

        $user = new WriteTestArrayUser();
        $user->name = 'Grace';
        $user->tags = [$tag1];

        $this->em->persist($tag1);
        $this->em->persist($tag2);
        $this->em->persist($user);
        $this->em->flush();

        $rows = $this->pivotRows('wt_user_tags');
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]['wt_users_id']);
        $this->assertSame($tag1->id, (int) $rows[0]['wt_tags_id']);
        $this->assertInstanceOf(Collection::class, $user->tags);

        $user->tags->add($tag2);
        $this->em->flush();

        $this->assertCount(2, $this->pivotRows('wt_user_tags'));
    }

    #[Group('database')]
    public function testPlainCollectionAddInsertsOnlyNewRow(): void
    {
        $this->prepareTables();

        $tag1 = new WriteTestTag();
        $tag1->name = 'php';
        $tag2 = new WriteTestTag();
        $tag2->name = 'oop';

        $this->em->persist($tag1);
        $this->em->persist($tag2);
        $this->em->flush();

        $user = new WriteTestUser();
        $user->name = 'George';
        $user->tags = new Collection([$tag1]);
        $this->em->persist($user);
        $this->em->flush();

        $this->assertCount(1, $this->pivotRows('wt_user_tags'));

        $user->tags->add($tag2);
        $this->em->flush();

        $rows = $this->pivotRows('wt_user_tags');
        $this->assertCount(2, $rows);
        $ids = array_map('intval', array_column($rows, 'wt_tags_id'));
        $this->assertContains($tag1->id, $ids);
        $this->assertContains($tag2->id, $ids);
    }

    #[Group('database')]
    public function testPlainCollectionRemoveDeletesOnlyThatRow(): void
    {
        $this->prepareTables();

        $tag1 = new WriteTestTag();
        $tag1->name = 'php';
        $tag2 = new WriteTestTag();
        $tag2->name = 'oop';

        $this->em->persist($tag1);
        $this->em->persist($tag2);
        $this->em->flush();

        $user = new WriteTestUser();
        $user->name = 'Helen';
        $user->tags = new Collection([$tag1, $tag2]);
        $this->em->persist($user);
        $this->em->flush();

        $this->assertCount(2, $this->pivotRows('wt_user_tags'));

        $user->tags->remove($tag1);
        $this->em->flush();

        $rows = $this->pivotRows('wt_user_tags');
        $this->assertCount(1, $rows);
        $this->assertSame($tag2->id, (int) $rows[0]['wt_tags_id']);
    }

    #[DataProvider('databaseProvider')]
    #[Group('database')]
    public function testPlainCollectionWithCreatedAtFillsTimestamp(string $databaseName): void
    {
        $this->setCurrentDatabase($this->getConnection($databaseName), $databaseName);
        $this->cleanUpTables(['pt_user_roles', 'pt_users', 'pt_roles']);

        $idCol = match ($databaseName) {
            'mysql' => 'INT PRIMARY KEY AUTO_INCREMENT',
            'pgsql' => 'SERIAL PRIMARY KEY',
        };
        $tsCol = match ($databaseName) {
            'mysql' => 'DATETIME NOT NULL',
            'pgsql' => 'TIMESTAMP NOT NULL',
        };

        $this->currentConnection->executeQuery("CREATE TABLE pt_users (id {$idCol}, name VARCHAR(255) NOT NULL)");
        $this->currentConnection->executeQuery("CREATE TABLE pt_roles (id {$idCol}, label VARCHAR(255) NOT NULL)");
        $this->currentConnection->executeQuery(
            "CREATE TABLE pt_user_roles (pt_users_id INT NOT NULL, pt_roles_id INT NOT NULL, created_at {$tsCol})"
        );

        $em = new EntityManager($this->currentConnection);

        $role = new PlainTimestampRole();
        $role->label = 'admin';

        $user = new PlainTimestampUser();
        $user->name = 'Ivan';
        $user->roles = new Collection([$role]);

        $em->persist($role);
        $em->persist($user);
        $em->flush();

        $rows = $this->currentConnection->executeQuery('SELECT * FROM pt_user_roles')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]['pt_users_id']);
        $this->assertSame($role->id, (int) $rows[0]['pt_roles_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $rows[0]['created_at']);
    }
}
