<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Modules\QueryBuilder\QueryBuilder;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Counts batched reads and can refuse any batch containing one chosen key,
 * standing in for a backend that cannot serve that entry.
 */
class QbJoinCachePool implements CacheItemPoolInterface {
    public int $getItemsCalls = 0;

    public ?string $unreadableKey = null;

    private ArrayAdapter $inner;

    public function __construct()
    {
        $this->inner = new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        return $this->inner->getItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $this->getItemsCalls++;
        if ($this->unreadableKey !== null && in_array($this->unreadableKey, $keys, true)) {
            throw new \RuntimeException('Backend unavailable');
        }

        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}

#[Entity(tableName: 'qb_join_authors')]
class QbJoinAuthor {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'qb_join_posts')]
class QbJoinPost {
    #[PrimaryKey]
    #[Property(name: 'post_id')]
    public ?int $postId = null;

    #[Property(name: 'author_ref')]
    public int $authorRef;

    #[Property]
    public int $published;
}

#[Entity(tableName: 'qb_join_tags')]
class QbJoinTag {
    #[PrimaryKey]
    #[Property(name: 'tag_id')]
    public ?int $tagId = null;

    #[Property]
    public string $label;
}

/**
 * A cacheable query's joined tables are dependency tables: a unit-of-work
 * write to any of them must invalidate the cached id-list.
 */
class QueryBuilderCacheableJoinInvalidationTest extends DatabaseTestCase {
    private const JOIN_TYPES = ['join', 'leftJoin', 'rightJoin', 'crossJoin'];

    #[DataProvider('databaseProvider')]
    public function testUpdatingAJoinedRowInvalidatesTheCachedListForEveryJoinType(string $databaseName): void
    {
        foreach (self::JOIN_TYPES as $joinType) {
            [$em, $connection] = $this->freshContext($databaseName);
            $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);

            $this->assertSame(['Ann'], $this->publishedAuthorNames($em, $joinType));

            $post = $em->find(QbJoinPost::class, $postId);
            $post->published = 0;
            $em->flush();
            $em->clear();

            $this->assertSame([], $this->publishedAuthorNames($em, $joinType), "{$joinType}: an update on the joined table must invalidate the list.");
        }
    }

    #[DataProvider('databaseProvider')]
    public function testInsertingAJoinedRowInvalidatesTheCachedListForEveryJoinType(string $databaseName): void
    {
        foreach (self::JOIN_TYPES as $joinType) {
            [$em, $connection] = $this->freshContext($databaseName);
            $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
            $connection->executeQuery('INSERT INTO qb_join_authors (name) VALUES (?)', ['Bob']);
            $bobId = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_join_authors_id_seq' : null);

            $this->assertSame(['Ann'], $this->publishedAuthorNames($em, $joinType));

            $post = new QbJoinPost();
            $post->authorRef = $bobId;
            $post->published = 1;
            $em->persist($post);
            $em->flush();
            $em->clear();

            $this->assertSame(['Ann', 'Bob'], $this->publishedAuthorNames($em, $joinType), "{$joinType}: an insert into the joined table must invalidate the list.");
        }
    }

    #[DataProvider('databaseProvider')]
    public function testDeletingAJoinedRowInvalidatesTheCachedListForEveryJoinType(string $databaseName): void
    {
        foreach (self::JOIN_TYPES as $joinType) {
            [$em, $connection] = $this->freshContext($databaseName);
            $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);

            $this->assertSame(['Ann'], $this->publishedAuthorNames($em, $joinType));

            $em->remove($em->find(QbJoinPost::class, $postId));
            $em->flush();
            $em->clear();

            $this->assertSame([], $this->publishedAuthorNames($em, $joinType), "{$joinType}: a delete on the joined table must invalidate the list.");
        }
    }

    #[DataProvider('databaseProvider')]
    public function testAnAliasedJoinInvalidatesOnWritesToTheRealTable(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
        $query = static fn (): array => $em->createQueryBuilder(QbJoinAuthor::class)
            ->join('qb_join_posts p', 'p.author_ref = id')
            ->where('p.published = ?', 1)
            ->orderBy('id')
            ->cacheable()
            ->getResult();

        $this->assertCount(1, $query());

        $post = $em->find(QbJoinPost::class, $postId);
        $post->published = 0;
        $em->flush();
        $em->clear();

        $this->assertSame([], $query(), 'A write to the aliased table must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testASelfJoinOfTheRootTableStaysCacheable(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
        $query = static fn (): array => array_map(
            static fn (QbJoinAuthor $author): string => $author->name,
            $em->createQueryBuilder(QbJoinAuthor::class)
                ->join('qb_join_authors twin', 'twin.id = qb_join_authors.id')
                ->orderBy('qb_join_authors.id')
                ->cacheable()
                ->getResult()
        );

        $this->assertSame(['Ann'], $query());

        $connection->executeQuery('INSERT INTO qb_join_authors (name) VALUES (?)', ['Unseen']);
        $em->clear();

        $this->assertSame(['Ann'], $query(), 'A self-join must still be served from the cache.');

        $author = new QbJoinAuthor();
        $author->name = 'Bob';
        $em->persist($author);
        $em->flush();
        $em->clear();

        $this->assertSame(['Ann', 'Unseen', 'Bob'], $query(), 'A write to the self-joined root table must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testWritingToATableTheQueryDoesNotReadKeepsTheListAHit(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);

        $this->assertSame(['Ann'], $this->publishedAuthorNames($em, 'join'));

        $connection->executeQuery('INSERT INTO qb_join_authors (name) VALUES (?)', ['Unseen']);
        $unseenId = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_join_authors_id_seq' : null);
        $connection->executeQuery('INSERT INTO qb_join_posts (author_ref, published) VALUES (?, ?)', [$unseenId, 1]);

        $tag = new QbJoinTag();
        $tag->label = 'php';
        $em->persist($tag);
        $em->flush();
        $em->clear();

        $this->assertSame(['Ann'], $this->publishedAuthorNames($em, 'join'), 'A write to an unrelated table must keep the list a hit.');
    }

    #[DataProvider('databaseProvider')]
    public function testAnUnreadableDependencyTableGenerationRunsTheQueryUncached(string $databaseName): void
    {
        $pool = new QbJoinCachePool();
        [$em, $connection] = $this->freshContext($databaseName, $pool);
        $pool->unreadableKey = 'l2qgen_' . hash('sha256', $connection->getCacheNamespace() . "\0" . 'qb_join_posts');
        $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);

        $this->assertSame(['Ann'], $this->publishedAuthorNames($em, 'join'));

        $this->insertAuthorWithPost($connection, $databaseName, 'Unseen', 1);
        $em->clear();

        $this->assertSame(['Ann', 'Unseen'], $this->publishedAuthorNames($em, 'join'), 'Without a readable generation the query must not be served from the cache.');
    }

    #[DataProvider('databaseProvider')]
    public function testAllGenerationsOfAJoinedQueryAreReadInOneBatch(string $databaseName): void
    {
        $pool = new QbJoinCachePool();
        [$em, $connection] = $this->freshContext($databaseName, $pool);
        $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);

        $this->publishedAuthorNames($em, 'join');
        $em->clear();
        $before = $pool->getItemsCalls;

        $this->assertSame(['Ann'], $this->publishedAuthorNames($em, 'join'));
        $this->assertSame(2, $pool->getItemsCalls - $before, 'One getItems() reads every table generation, one resolves the entities.');
    }

    /**
     * @return array{EntityManager, Connection}
     */
    private function freshContext(string $databaseName, ?QbJoinCachePool $pool = null): array
    {
        $connection = $this->getConnection($databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $this->createTables($connection, $databaseName);

        return [new EntityManager($connection, secondLevelCache: $pool ?? new QbJoinCachePool()), $connection];
    }

    /**
     * @return list<string>
     */
    private function publishedAuthorNames(EntityManager $em, string $joinType): array
    {
        $qb = $em->createQueryBuilder(QbJoinAuthor::class);
        $qb = $this->applyJoin($qb, $joinType, 'qb_join_posts', 'author_ref = id');

        $result = $qb->where('published = ?', 1)->orderBy('id')->cacheable()->getResult();

        return array_map(static fn (QbJoinAuthor $author): string => $author->name, $result);
    }

    private function applyJoin(QueryBuilder $qb, string $joinType, string $table, string $condition): QueryBuilder
    {
        return match ($joinType) {
            'join' => $qb->join($table, $condition),
            'leftJoin' => $qb->leftJoin($table, $condition),
            'rightJoin' => $qb->rightJoin($table, $condition),
            'crossJoin' => $qb->crossJoin($table)->where($condition),
        };
    }

    private function insertAuthorWithPost(Connection $connection, string $databaseName, string $name, int $published): int
    {
        $connection->executeQuery('INSERT INTO qb_join_authors (name) VALUES (?)', [$name]);
        $authorId = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_join_authors_id_seq' : null);

        $connection->executeQuery('INSERT INTO qb_join_posts (author_ref, published) VALUES (?, ?)', [$authorId, $published]);

        return (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_join_posts_post_id_seq' : null);
    }

    private function createTables(Connection $connection, string $databaseName): void
    {
        $tables = match ($databaseName) {
            'pgsql' => [
                'qb_join_authors' => 'CREATE TABLE qb_join_authors (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL)',
                'qb_join_posts' => 'CREATE TABLE qb_join_posts (post_id SERIAL PRIMARY KEY, author_ref INT NOT NULL, published INT NOT NULL)',
                'qb_join_tags' => 'CREATE TABLE qb_join_tags (tag_id SERIAL PRIMARY KEY, label VARCHAR(255) NOT NULL)',
            ],
            'mysql' => [
                'qb_join_authors' => 'CREATE TABLE qb_join_authors (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL)',
                'qb_join_posts' => 'CREATE TABLE qb_join_posts (post_id INT AUTO_INCREMENT PRIMARY KEY, author_ref INT NOT NULL, published INT NOT NULL)',
                'qb_join_tags' => 'CREATE TABLE qb_join_tags (tag_id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(255) NOT NULL)',
            ],
        };

        foreach ($tables as $table => $create) {
            $connection->executeQuery($databaseName === 'pgsql' ? "DROP TABLE IF EXISTS {$table} CASCADE" : "DROP TABLE IF EXISTS {$table}");
            $connection->executeQuery($create);
        }
    }
}
