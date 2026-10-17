<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Tests\Mirror;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Doctrine\Mirror\EntityHydrator;
use Symfony\AI\Store\Bridge\Doctrine\Mirror\MirroredEntityLoader;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Author;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\AuthorProfile;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Book;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\BookTranslation;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class EntityHydratorTest extends TestCase
{
    use OrmEntityManagerTrait;

    private EntityManager $entityManager;
    private MirroredEntityLoader $loader;

    protected function setUp(): void
    {
        $this->entityManager = $this->createOrmEntityManager();
        $this->loader = new MirroredEntityLoader($this->entityManager);
    }

    public function testHydrateReturnsManagedEntitiesInDocumentOrder()
    {
        $dune = new Book(1, 'Dune', 'A desert planet and a spice empire.');
        $neuromancer = new Book(2, 'Neuromancer', 'A washed-up hacker in cyberspace.');
        $emma = new Book(3, 'Emma', 'Matchmaking in Regency England.');
        $this->persist([$dune, $neuromancer, $emma]);

        $hydrator = new EntityHydrator($this->entityManager);

        $entities = $hydrator->hydrate([
            $this->loader->loadEntity($emma),
            $this->loader->loadEntity($dune),
            $this->loader->loadEntity($neuromancer),
        ]);

        $this->assertSame([$emma, $dune, $neuromancer], $entities);
    }

    public function testHydrateAcceptsVectorDocuments()
    {
        $book = new Book(1, 'Dune', 'A desert planet and a spice empire.');
        $this->persist([$book]);

        $textDocument = $this->loader->loadEntity($book);
        $vectorDocument = new VectorDocument($textDocument->getId(), new Vector([1.0, 0.0]), $textDocument->getMetadata());

        $hydrator = new EntityHydrator($this->entityManager);

        $this->assertSame([$book], $hydrator->hydrate([$vectorDocument]));
    }

    public function testHydratePreservesInterleavedOrderAcrossEntityClasses()
    {
        $dune = new Book(1, 'Dune', 'A desert planet and a spice empire.');
        $herbert = new Author(1, 'Frank Herbert');
        $neuromancer = new Book(2, 'Neuromancer', 'A washed-up hacker in cyberspace.');
        $this->persist([$dune, $herbert, $neuromancer]);

        $hydrator = new EntityHydrator($this->entityManager);

        $entities = $hydrator->hydrate([
            $this->loader->loadEntity($dune),
            $this->loader->loadEntity($herbert),
            $this->loader->loadEntity($neuromancer),
        ]);

        $this->assertSame([$dune, $herbert, $neuromancer], $entities);
    }

    public function testHydrateResolvesCompositeIdentifiers()
    {
        $english = new BookTranslation(1, 'en', 'Dune');
        $german = new BookTranslation(1, 'de', 'Der Wüstenplanet');
        $this->persist([$english, $german]);

        $hydrator = new EntityHydrator($this->entityManager);

        $entities = $hydrator->hydrate([
            $this->loader->loadEntity($german),
            $this->loader->loadEntity($english),
        ]);

        $this->assertSame([$german, $english], $entities);
    }

    public function testHydrateResolvesAssociationIdentifiers()
    {
        $herbert = new Author(1, 'Frank Herbert');
        $profile = new AuthorProfile($herbert, 'Author of the Dune saga.');
        $this->persist([$herbert, $profile]);

        $hydrator = new EntityHydrator($this->entityManager);

        $this->assertSame([$profile], $hydrator->hydrate([$this->loader->loadEntity($profile)]));
    }

    public function testHydrateSkipsDocumentsWhoseClassIsNotAMappedEntity()
    {
        $document = new TextDocument('some-id', 'Indexed against a mapping that no longer exists.', new Metadata([
            EntityHydrator::METADATA_ENTITY_CLASS => \stdClass::class,
            EntityHydrator::METADATA_ENTITY_IDENTIFIER => ['id' => 1],
        ]));

        $skipped = [];
        $hydrator = new EntityHydrator($this->entityManager, static function (EmbeddableDocumentInterface|VectorDocumentInterface $document) use (&$skipped): void {
            $skipped[] = $document;
        });

        $this->assertSame([], $hydrator->hydrate([$document]));
        $this->assertSame([$document], $skipped);
    }

    public function testHydrateSkipsCompositeDocumentsWithStaleIdentifierFields()
    {
        $english = new BookTranslation(1, 'en', 'Dune');
        $this->persist([$english]);

        $missingField = new TextDocument('missing-field', 'Indexed before a second identifier field was added.', new Metadata([
            EntityHydrator::METADATA_ENTITY_CLASS => BookTranslation::class,
            EntityHydrator::METADATA_ENTITY_IDENTIFIER => ['bookId' => 1],
        ]));
        $renamedField = new TextDocument('renamed-field', 'Indexed before an identifier field was renamed.', new Metadata([
            EntityHydrator::METADATA_ENTITY_CLASS => BookTranslation::class,
            EntityHydrator::METADATA_ENTITY_IDENTIFIER => ['bookId' => 1, 'language' => 'en'],
        ]));

        $skipped = [];
        $hydrator = new EntityHydrator($this->entityManager, static function (EmbeddableDocumentInterface|VectorDocumentInterface $document) use (&$skipped): void {
            $skipped[] = $document;
        });

        $entities = $hydrator->hydrate([$missingField, $this->loader->loadEntity($english), $renamedField]);

        $this->assertSame([$english], $entities);
        $this->assertSame([$missingField, $renamedField], $skipped);
    }

    public function testHydrateSkipsStaleDocumentsAndReportsThem()
    {
        $dune = new Book(1, 'Dune', 'A desert planet and a spice empire.');
        $emma = new Book(3, 'Emma', 'Matchmaking in Regency England.');
        $this->persist([$dune, $emma]);

        $staleDocument = $this->loader->loadEntity($emma);
        $freshDocument = $this->loader->loadEntity($dune);

        $this->entityManager->remove($emma);
        $this->entityManager->flush();

        $skipped = [];
        $hydrator = new EntityHydrator($this->entityManager, static function (EmbeddableDocumentInterface|VectorDocumentInterface $document) use (&$skipped): void {
            $skipped[] = $document;
        });

        $entities = $hydrator->hydrate([$staleDocument, $freshDocument]);

        $this->assertSame([$dune], $entities);
        $this->assertSame([$staleDocument], $skipped);
    }

    public function testHydrateSkipsDocumentsWithoutIdentityMetadata()
    {
        $document = new TextDocument('some-id', 'A document that was not indexed from an entity.');

        $skipped = [];
        $hydrator = new EntityHydrator($this->entityManager, static function (EmbeddableDocumentInterface|VectorDocumentInterface $document) use (&$skipped): void {
            $skipped[] = $document;
        });

        $this->assertSame([], $hydrator->hydrate([$document]));
        $this->assertSame([$document], $skipped);
    }

    public function testHydrateMatchesIdentifiersAcrossValueTypes()
    {
        $book = new Book(7, 'Dune', 'A desert planet and a spice empire.');
        $this->persist([$book]);

        $document = $this->loader->loadEntity($book);

        // simulate a JSON round trip through a real vector store, turning the integer identifier into a string
        $metadata = $document->getMetadata();
        $this->assertSame(['id' => 7], $metadata[EntityHydrator::METADATA_ENTITY_IDENTIFIER]);
        $metadata[EntityHydrator::METADATA_ENTITY_IDENTIFIER] = ['id' => '7'];

        $hydrator = new EntityHydrator($this->entityManager);

        $this->assertSame([$book], $hydrator->hydrate([$document]));
    }

    /**
     * @param list<object> $entities
     */
    private function persist(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }
}
