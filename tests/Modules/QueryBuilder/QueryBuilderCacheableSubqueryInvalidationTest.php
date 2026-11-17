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
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Entity(tableName: 'qb_sub_authors')]
class QbSubAuthor {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name;
}

#[Entity(tableName: 'qb_sub_posts')]
class QbSubPost {
    #[PrimaryKey]
    #[Property(name: 'post_id')]
    public ?int $postId = null;

    #[Property(name: 'author_ref')]
    public int $authorRef;

    #[Property]
    public int $published;
}

#[Entity(tableName: 'qb_sub_comments')]
class QbSubComment {
    #[PrimaryKey]
    #[Property(name: 'comment_id')]
    public ?int $commentId = null;

    #[Property(name: 'post_ref')]
    public int $postRef;

    #[Property]
    public int $approved;
}

/**
 * Tables a cacheable query reads through a subquery are dependency tables:
 * a unit-of-work write to any of them must invalidate the cached id-list.
 */
class QueryBuilderCacheableSubqueryInvalidationTest extends DatabaseTestCase {
    private const SUBQUERY_FORMS = ['whereExists', 'orWhereExists', 'whereIn', 'whereNotIn', 'groupedWhereExists'];

    #[DataProvider('databaseProvider')]
    public function testUpdatingASubqueryRowInvalidatesTheCachedListForEverySubqueryForm(string $databaseName): void
    {
        foreach (self::SUBQUERY_FORMS as $form) {
            [$em, $connection] = $this->freshContext($databaseName);
            $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
            $expectedBefore = $form === 'whereNotIn' ? [] : ['Ann'];
            $expectedAfter = $form === 'whereNotIn' ? ['Ann'] : [];

            $this->assertSame($expectedBefore, $this->authorNames($em, fn (QueryBuilder $qb): QueryBuilder => $this->applySubqueryForm($qb, $form)));

            $post = $em->find(QbSubPost::class, $postId);
            $post->published = 0;
            $em->flush();
            $em->clear();

            $this->assertSame(
                $expectedAfter,
                $this->authorNames($em, fn (QueryBuilder $qb): QueryBuilder => $this->applySubqueryForm($qb, $form)),
                "{$form}: an update on the subquery table must invalidate the list."
            );
        }
    }

    #[DataProvider('databaseProvider')]
    public function testWritingToATableOfANestedSubqueryInvalidatesTheCachedList(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
        $commentId = $this->insertComment($connection, $databaseName, $postId, 1);
        $query = fn (): array => $this->authorNames($em, static function (QueryBuilder $qb): QueryBuilder {
            $approvedComments = $qb->createSubQueryBuilder()
                ->select('post_ref')
                ->from('qb_sub_comments')
                ->where('approved = ?', 1);
            $commentedPosts = $qb->createSubQueryBuilder()
                ->select('author_ref')
                ->from('qb_sub_posts')
                ->whereIn('post_id', $approvedComments);

            return $qb->whereIn('id', $commentedPosts);
        });

        $this->assertSame(['Ann'], $query());

        $comment = $em->find(QbSubComment::class, $commentId);
        $comment->approved = 0;
        $em->flush();
        $em->clear();

        $this->assertSame([], $query(), 'A write to a nested subquery table must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testWritingToATableJoinedInsideASubqueryInvalidatesTheCachedList(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
        $commentId = $this->insertComment($connection, $databaseName, $postId, 1);
        $query = fn (): array => $this->authorNames($em, static function (QueryBuilder $qb): QueryBuilder {
            $authorsWithApprovedComments = $qb->createSubQueryBuilder()
                ->select('p.author_ref')
                ->from('qb_sub_posts', 'p')
                ->join('qb_sub_comments c', 'c.post_ref = p.post_id')
                ->where('c.approved = ?', 1);

            return $qb->whereIn('id', $authorsWithApprovedComments);
        });

        $this->assertSame(['Ann'], $query());

        $comment = $em->find(QbSubComment::class, $commentId);
        $comment->approved = 0;
        $em->flush();
        $em->clear();

        $this->assertSame([], $query(), 'A write to a table joined inside a subquery must invalidate the list.');
    }

    #[DataProvider('databaseProvider')]
    public function testResettingTheOuterJoinsKeepsSubqueryTablesAsDependencies(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $postId = $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
        $query = fn (): array => $this->authorNames(
            $em,
            fn (QueryBuilder $qb): QueryBuilder => $this->applySubqueryForm($qb, 'whereExists')
                ->join('qb_sub_comments', '1 = 0')
                ->resetJoins()
        );

        $this->assertSame(['Ann'], $query());

        $post = $em->find(QbSubPost::class, $postId);
        $post->published = 0;
        $em->flush();
        $em->clear();

        $this->assertSame([], $query(), 'resetJoins() must not drop the subquery tables.');
    }

    #[DataProvider('databaseProvider')]
    public function testWritingToATableNoSubqueryReadsKeepsTheListAHit(string $databaseName): void
    {
        [$em, $connection] = $this->freshContext($databaseName);
        $this->insertAuthorWithPost($connection, $databaseName, 'Ann', 1);
        $query = fn (): array => $this->authorNames($em, fn (QueryBuilder $qb): QueryBuilder => $this->applySubqueryForm($qb, 'whereExists'));

        $this->assertSame(['Ann'], $query());

        $this->insertAuthorWithPost($connection, $databaseName, 'Unseen', 1);
        $comment = new QbSubComment();
        $comment->postRef = 0;
        $comment->approved = 1;
        $em->persist($comment);
        $em->flush();
        $em->clear();

        $this->assertSame(['Ann'], $query(), 'A write to a table no subquery reads must keep the list a hit.');
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
     * @param callable(QueryBuilder): QueryBuilder $filter
     * @return list<string>
     */
    private function authorNames(EntityManager $em, callable $filter): array
    {
        $qb = $filter($em->createQueryBuilder(QbSubAuthor::class));

        return array_map(
            static fn (QbSubAuthor $author): string => $author->name,
            $qb->orderBy('id')->cacheable()->getResult()
        );
    }

    /**
     * @param 'whereExists'|'orWhereExists'|'whereIn'|'whereNotIn'|'groupedWhereExists' $form
     */
    private function applySubqueryForm(QueryBuilder $qb, string $form): QueryBuilder
    {
        $publishedPost = $qb->createSubQueryBuilder()
            ->select('1')
            ->from('qb_sub_posts')
            ->where('author_ref = qb_sub_authors.id')
            ->where('published = ?', 1);
        $publishedAuthorRefs = $qb->createSubQueryBuilder()
            ->select('author_ref')
            ->from('qb_sub_posts')
            ->where('published = ?', 1);

        return match ($form) {
            'whereExists' => $qb->whereExists($publishedPost),
            'orWhereExists' => $qb->where('name = ?', 'nobody')->orWhereExists($publishedPost),
            'whereIn' => $qb->whereIn('id', $publishedAuthorRefs),
            'whereNotIn' => $qb->whereNotIn('id', $publishedAuthorRefs),
            'groupedWhereExists' => $qb->where(static fn (QueryBuilder $group): QueryBuilder => $group->whereExists($publishedPost)),
        };
    }

    private function insertAuthorWithPost(Connection $connection, string $databaseName, string $name, int $published): int
    {
        $connection->executeQuery('INSERT INTO qb_sub_authors (name) VALUES (?)', [$name]);
        $authorId = (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_sub_authors_id_seq' : null);

        $connection->executeQuery('INSERT INTO qb_sub_posts (author_ref, published) VALUES (?, ?)', [$authorId, $published]);

        return (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_sub_posts_post_id_seq' : null);
    }

    private function insertComment(Connection $connection, string $databaseName, int $postId, int $approved): int
    {
        $connection->executeQuery('INSERT INTO qb_sub_comments (post_ref, approved) VALUES (?, ?)', [$postId, $approved]);

        return (int) $connection->lastInsertId($databaseName === 'pgsql' ? 'qb_sub_comments_comment_id_seq' : null);
    }

    /**
     * @param 'mysql'|'pgsql' $databaseName
     */
    private function createTables(Connection $connection, string $databaseName): void
    {
        $tables = match ($databaseName) {
            'pgsql' => [
                'qb_sub_authors' => 'CREATE TABLE qb_sub_authors (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL)',
                'qb_sub_posts' => 'CREATE TABLE qb_sub_posts (post_id SERIAL PRIMARY KEY, author_ref INT NOT NULL, published INT NOT NULL)',
                'qb_sub_comments' => 'CREATE TABLE qb_sub_comments (comment_id SERIAL PRIMARY KEY, post_ref INT NOT NULL, approved INT NOT NULL)',
            ],
            'mysql' => [
                'qb_sub_authors' => 'CREATE TABLE qb_sub_authors (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL)',
                'qb_sub_posts' => 'CREATE TABLE qb_sub_posts (post_id INT AUTO_INCREMENT PRIMARY KEY, author_ref INT NOT NULL, published INT NOT NULL)',
                'qb_sub_comments' => 'CREATE TABLE qb_sub_comments (comment_id INT AUTO_INCREMENT PRIMARY KEY, post_ref INT NOT NULL, approved INT NOT NULL)',
            ],
        };

        foreach ($tables as $table => $create) {
            $connection->executeQuery($databaseName === 'pgsql' ? "DROP TABLE IF EXISTS {$table} CASCADE" : "DROP TABLE IF EXISTS {$table}");
            $connection->executeQuery($create);
        }
    }
}
