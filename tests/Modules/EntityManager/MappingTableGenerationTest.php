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
use Articulate\Connection;
use Articulate\Modules\Cache\CollectionCache;
use Articulate\Modules\EntityManager\Collection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Entity(tableName: 'mtg_tags')]
class MtgTag {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'mtg_posts')]
class MtgPost {
    #[PrimaryKey]
    public int $id;

    #[Property]
    public string $title;

    #[ManyToMany(targetEntity: MtgTag::class, mappingTable: new MappingTable(name: 'mtg_post_tags'))]
    public ?Collection $tags = null;

    #[ManyToMany(
        targetEntity: MtgTag::class,
        mappingTable: new MappingTable(name: 'mtg_post_labels', properties: [new MappingTableProperty(name: 'note')])
    )]
    public ?MappingCollection $labels = null;
}

/**
 * Mapping tables the unit of work writes are dependency tables like any
 * other: a cached list joining one must be invalidated by the flush.
 */
class MappingTableGenerationTest extends DatabaseTestCase {
    #[DataProvider('databaseProvider')]
    public function testRemovingAnOwnerWhosePivotRowsAreDeletedInvalidatesAListJoiningTheMappingTable(string $databaseName): void
    {
        $em = $this->freshContext($databaseName);
        $tag = $this->persistTag($em, 'php');
        $post = $this->persistPost($em, tags: [$tag]);

        $this->assertSame(['php'], $this->taggedNames($em));

        $em->remove($post);
        $em->flush();

        $this->assertSame([], $this->taggedNames($em), 'Deleting the pivot rows must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testAddingToAManagedCollectionInvalidatesAListJoiningTheMappingTable(string $databaseName): void
    {
        $em = $this->freshContext($databaseName);
        $php = $this->persistTag($em, 'php');
        $sql = $this->persistTag($em, 'sql');
        $post = $this->persistPost($em, tags: [$php]);

        $this->assertSame(['php'], $this->taggedNames($em));

        $post->tags->add($sql);
        $em->flush();

        $this->assertSame(['php', 'sql'], $this->taggedNames($em), 'A pivot insert must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testRemovingFromAManagedMappingCollectionInvalidatesAListJoiningTheMappingTable(string $databaseName): void
    {
        $em = $this->freshContext($databaseName);
        $tag = $this->persistTag($em, 'php');
        $post = $this->persistPost($em, labels: [$tag]);

        $this->assertSame(['php'], $this->labelledNames($em));

        $post->labels->remove($tag);
        $em->flush();

        $this->assertSame([], $this->labelledNames($em), 'A pivot delete must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testAMappingTableWrittenByARolledBackFlushIsNotBumpedByTheNextFlush(string $databaseName): void
    {
        $pool = new ArrayAdapter();
        $em = $this->freshContext($databaseName, $pool);
        $connection = $this->getCurrentConnection();
        $generations = new CollectionCache($pool, $connection->getCacheNamespace());
        $post = $this->persistPost($em, tags: [$this->persistTag($em, 'php')]);
        $connection->executeQuery('INSERT INTO mtg_post_pins (post_id) VALUES (?)', [$post->id]);
        $before = $generations->getTableGenerations(['mtg_post_tags'])['mtg_post_tags'];

        $em->remove($post);

        try {
            $em->flush();
            $this->fail('The post delete was expected to violate the pin foreign key.');
        } catch (\PDOException) {
        }

        $em->clear();
        $em->flush();

        $this->assertSame($before, $generations->getTableGenerations(['mtg_post_tags'])['mtg_post_tags'], 'A pivot delete rolled back with its flush must not be bumped by the next flush.');
    }

    private function freshContext(string $databaseName, ?ArrayAdapter $pool = null): EntityManager
    {
        $connection = $this->getConnection($databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $this->createTables($connection, $databaseName);

        return new EntityManager($connection, secondLevelCache: $pool ?? new ArrayAdapter());
    }

    private function persistTag(EntityManager $em, string $name): MtgTag
    {
        $tag = new MtgTag();
        $tag->name = $name;
        $em->persist($tag);
        $em->flush();

        return $tag;
    }

    /**
     * @param list<MtgTag> $tags
     * @param list<MtgTag> $labels
     */
    private function persistPost(EntityManager $em, array $tags = [], array $labels = []): MtgPost
    {
        $post = new MtgPost();
        $post->title = 'post';
        $post->tags = new Collection($tags);
        $post->labels = new MappingCollection(array_map(static fn (MtgTag $tag): MappingItem => new MappingItem($tag, ['note' => 'n']), $labels));
        $em->persist($post);
        $em->flush();

        return $post;
    }

    /**
     * @return list<string>
     */
    private function taggedNames(EntityManager $em): array
    {
        return $this->joinedNames($em, 'mtg_post_tags');
    }

    /**
     * @return list<string>
     */
    private function labelledNames(EntityManager $em): array
    {
        return $this->joinedNames($em, 'mtg_post_labels');
    }

    /**
     * @return list<string>
     */
    private function joinedNames(EntityManager $em, string $mappingTable): array
    {
        $result = $em->createQueryBuilder(MtgTag::class)
            ->join("{$mappingTable} pivot", 'pivot.mtg_tags_id = mtg_tags.id')
            ->orderBy('mtg_tags.id')
            ->cacheable()
            ->getResult();

        return array_map(static fn (MtgTag $tag): string => $tag->name, $result);
    }

    private function createTables(Connection $connection, string $databaseName): void
    {
        $id = $databaseName === 'pgsql' ? 'id SERIAL PRIMARY KEY' : 'id INT AUTO_INCREMENT PRIMARY KEY';
        $tables = [
            'mtg_post_pins' => 'CREATE TABLE mtg_post_pins (post_id INT NOT NULL, FOREIGN KEY (post_id) REFERENCES mtg_posts (id))',
            'mtg_post_tags' => 'CREATE TABLE mtg_post_tags (mtg_posts_id INT NOT NULL, mtg_tags_id INT NOT NULL)',
            'mtg_post_labels' => 'CREATE TABLE mtg_post_labels (mtg_posts_id INT NOT NULL, mtg_tags_id INT NOT NULL, note VARCHAR(50) NOT NULL)',
            'mtg_posts' => "CREATE TABLE mtg_posts ({$id}, title VARCHAR(255) NOT NULL)",
            'mtg_tags' => "CREATE TABLE mtg_tags ({$id}, name VARCHAR(255) NOT NULL)",
        ];

        foreach (array_keys($tables) as $table) {
            $connection->executeQuery($databaseName === 'pgsql' ? "DROP TABLE IF EXISTS {$table} CASCADE" : "DROP TABLE IF EXISTS {$table}");
        }

        foreach (array_reverse($tables) as $create) {
            $connection->executeQuery($create);
        }
    }
}
