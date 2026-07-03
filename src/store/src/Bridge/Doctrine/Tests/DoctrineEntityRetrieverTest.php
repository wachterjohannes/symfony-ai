<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Tests;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityHydrator;
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityLoader;
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityRetriever;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Book;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\InMemory\Store;
use Symfony\AI\Store\Retriever;

/**
 * Round-trip test for the out-of-the-box story: entities are indexed with the DoctrineEntityLoader and a
 * semantic query returns the very same managed entities, in similarity ranking order.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class DoctrineEntityRetrieverTest extends TestCase
{
    use OrmEntityManagerTrait;

    public function testRetrieveReturnsEntitiesRankedBySimilarity()
    {
        $entityManager = $this->createOrmEntityManager();

        $dune = new Book(1, 'Dune', 'A desert planet and a spice empire.');
        $neuromancer = new Book(2, 'Neuromancer', 'A washed-up hacker in cyberspace.');
        $emma = new Book(3, 'Emma', 'Matchmaking in Regency England.');
        foreach ([$dune, $neuromancer, $emma] as $book) {
            $entityManager->persist($book);
        }
        $entityManager->flush();

        // index: render each entity to a document and store it with a fixed embedding
        $store = new Store();
        $this->index($store, $entityManager, [
            1 => new Vector([0.9, 0.1]),
            2 => new Vector([1.0, 0.0]),
            3 => new Vector([0.0, 1.0]),
        ]);

        // retrieve: the query embeds closest to Neuromancer, then Dune, then Emma
        $retriever = new DoctrineEntityRetriever(
            new Retriever($store, $this->createQueryVectorizer(new Vector([1.0, 0.0]))),
            new DoctrineEntityHydrator($entityManager),
        );

        $entities = $retriever->retrieve('books about hackers');

        $this->assertSame([$neuromancer, $dune, $emma], $entities);
    }

    public function testRetrieveSkipsEntitiesDeletedAfterIndexing()
    {
        $entityManager = $this->createOrmEntityManager();

        $dune = new Book(1, 'Dune', 'A desert planet and a spice empire.');
        $emma = new Book(3, 'Emma', 'Matchmaking in Regency England.');
        $entityManager->persist($dune);
        $entityManager->persist($emma);
        $entityManager->flush();

        $store = new Store();
        $this->index($store, $entityManager, [
            1 => new Vector([1.0, 0.0]),
            3 => new Vector([0.9, 0.1]),
        ]);

        // the store still references Emma, but the record is gone
        $entityManager->remove($emma);
        $entityManager->flush();

        $retriever = new DoctrineEntityRetriever(
            new Retriever($store, $this->createQueryVectorizer(new Vector([1.0, 0.0]))),
            new DoctrineEntityHydrator($entityManager),
        );

        $this->assertSame([$dune], $retriever->retrieve('desert planet'));
    }

    /**
     * @param array<int, Vector> $vectorsByBookId
     */
    private function index(Store $store, EntityManager $entityManager, array $vectorsByBookId): void
    {
        $loader = new DoctrineEntityLoader($entityManager);

        foreach ($loader->load(Book::class) as $document) {
            $identifier = $document->getMetadata()[DoctrineEntityHydrator::METADATA_ENTITY_IDENTIFIER];
            $this->assertIsArray($identifier);

            $store->add(new VectorDocument($document->getId(), $vectorsByBookId[$identifier['id']], $document->getMetadata()));
        }
    }

    private function createQueryVectorizer(Vector $queryVector): VectorizerInterface
    {
        return new class($queryVector) implements VectorizerInterface {
            public function __construct(
                private readonly Vector $queryVector,
            ) {
            }

            public function vectorize(string|\Stringable|EmbeddableDocumentInterface|array $values, array $options = []): Vector
            {
                return $this->queryVector;
            }
        };
    }
}
