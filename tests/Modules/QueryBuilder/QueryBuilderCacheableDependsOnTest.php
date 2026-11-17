<?php

namespace Articulate\Tests\Modules\QueryBuilder;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Connection;
use Articulate\Modules\EntityManager\EntityManager;
use Articulate\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Entity(tableName: 'qb_dep_authors')]
class QbDepAuthor {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'qb_dep_posts')]
class QbDepPost {
    #[PrimaryKey]
    #[Property(name: 'post_id')]
    public ?int $postId = null;

    #[Property(name: 'author_ref')]
    public int $authorRef;

    #[Property]
    public int $published;
}

/**
 * cacheable(dependsOn:) declares dependency tables the query builder cannot
 * detect, such as tables reached only through whereRaw().
 */
class QueryBuilderCacheableDependsOnTest extends DatabaseTestCase {
    private const RAW_EXISTS = 'EXISTS (SELECT 1 FROM qb_dep_posts WHERE qb_dep_posts.author_ref = qb_dep_authors.id AND qb_dep_posts.published = 1)';

    #[DataProvider('databaseProvider')]
    public function testADeclaredTableReachedThroughWhereRawInvalidatesTheList(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann');

        $this->assertSame(['Ann'], $this->authorsWithPublishedPosts($em, ['qb_dep_posts']));

        $post = $em->find(QbDepPost::class, $postId);
        $post->published = 0;
        $em->flush();
        $em->clear();

        $this->assertSame([], $this->authorsWithPublishedPosts($em, ['qb_dep_posts']), 'A write to a declared table must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testWithoutDependsOnATableReachedOnlyThroughWhereRawIsNotDetected(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann');

        $this->assertSame(['Ann'], $this->authorsWithPublishedPosts($em, []));

        $post = $em->find(QbDepPost::class, $postId);
        $post->published = 0;
        $em->flush();
        $em->clear();

        $this->assertSame(['Ann'], $this->authorsWithPublishedPosts($em, []), 'An undeclared raw-SQL dependency must not invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testDeclaringAlreadyDetectedTablesKeepsTheSameKey(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $this->insertAuthorWithPost($connection, $databaseName, 'Ann');
        $query = static fn (array $dependsOn): array => array_map(
            static fn (QbDepAuthor $author): string => $author->name,
            $em->createQueryBuilder(QbDepAuthor::class)
                ->join('qb_dep_posts p', 'p.author_ref = qb_dep_authors.id')
                ->orderBy('qb_dep_authors.id')
                ->cacheable(dependsOn: $dependsOn)
                ->getResult()
        );

        $this->assertSame(['Ann'], $query([]));

        $this->insertAuthorWithPost($connection, $databaseName, 'Unseen');
        $em->clear();

        $this->assertSame(['Ann'], $query(['qb_dep_posts', 'qb_dep_authors', 'qb_dep_posts']), 'Declaring detected tables must hit the list cached without them.');
    }

    #[DataProvider('databaseProvider')]
    public function testDeclaredTablesSurviveResetJoins(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann');
        $query = static fn (): array => array_map(
            static fn (QbDepAuthor $author): string => $author->name,
            $em->createQueryBuilder(QbDepAuthor::class)
                ->cacheable(dependsOn: ['qb_dep_posts'])
                ->join('qb_dep_posts p', 'p.author_ref = qb_dep_authors.id')
                ->resetJoins()
                ->whereRaw(self::RAW_EXISTS)
                ->orderBy('id')
                ->getResult()
        );

        $this->assertSame(['Ann'], $query());

        $post = $em->find(QbDepPost::class, $postId);
        $post->published = 0;
        $em->flush();
        $em->clear();

        $this->assertSame([], $query(), 'resetJoins() must not drop declared dependency tables.');
    }

    /**
     * @return array{EntityManager, Connection}
     */
    private function freshContext(string $databaseName): array
    {
        $connection = $this->getConnection($databaseName);
        $this->setCurrentDatabase($connection, $databaseName);
        $this->createTables($connection, $databaseName);

        return [new EntityManager($connection, secondLevelCache: new ArrayAdapter()), $connection];
    }

    /**
     * @param list<string> $dependsOn
     * @return list<string>
     */
    private function authorsWithPublishedPosts(EntityManager $em, array $dependsOn): array
    {
        $result = $em->createQueryBuilder(QbDepAuthor::class)
            ->whereRaw(self::RAW_EXISTS)
            ->orderBy('id')
            ->cacheable(dependsOn: $dependsOn)
            ->getResult();

        return array_map(static fn (QbDepAuthor $author): string => $author->name, $result);
    }

    private function insertAuthorWithPost(Connection $connection, string $databaseName, string $name): int
    {
        $connection->executeQuery('INSERT INTO qb_dep_authors (name) VALUES (?)', [$name]);
        $authorId = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_dep_authors_id_seq' : null);

        $connection->executeQuery('INSERT INTO qb_dep_posts (author_ref, published) VALUES (?, ?)', [$authorId, 1]);

        return (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_dep_posts_post_id_seq' : null);
    }

    private function createTables(Connection $connection, string $databaseName): void
    {
        $id = $databaseName === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $tables = [
            'qb_dep_authors' => "CREATE TABLE qb_dep_authors (id {$id}, name VARCHAR(255) NOT NULL)",
            'qb_dep_posts' => "CREATE TABLE qb_dep_posts (post_id {$id}, author_ref INT NOT NULL, published INT NOT NULL)",
        ];

        foreach ($tables as $table => $create) {
            $connection->executeQuery($databaseName === 'pgsql' ? "DROP TABLE IF EXISTS {$table} CASCADE" : "DROP TABLE IF EXISTS {$table}");
            $connection->executeQuery($create);
        }
    }
}
