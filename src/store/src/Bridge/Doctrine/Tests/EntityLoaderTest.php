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

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Bridge\Doctrine\EntityContentRenderer;
use Symfony\AI\Store\Bridge\Doctrine\EntityDocument;
use Symfony\AI\Store\Bridge\Doctrine\EntityLoader;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Article;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Note;
use Symfony\AI\Store\Bridge\Doctrine\Type\VectorType;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class EntityLoaderTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        if (!Type::hasType(VectorType::NAME)) {
            Type::addType(VectorType::NAME, VectorType::class);
        }

        $configuration = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/Fixtures'], true);

        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->entityManager = new EntityManager($connection, $configuration);

        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(Article::class),
            $this->entityManager->getClassMetadata(Note::class),
        ]);
    }

    public function testWalksEveryEntityAcrossPageBoundaries(): void
    {
        foreach (['First', 'Second', 'Third', 'Fourth', 'Fifth'] as $title) {
            $this->entityManager->persist(new Article($title));
        }
        $this->entityManager->flush();

        $loader = new EntityLoader($this->entityManager, Article::class, batchSize: 2);
        $documents = iterator_to_array($loader->load(), false);

        $this->assertCount(5, $documents);
        $this->assertContainsOnlyInstancesOf(EntityDocument::class, $documents);
        $this->assertSame(
            ['First', 'Second', 'Third', 'Fourth', 'Fifth'],
            array_map(static fn (EntityDocument $document): string => $document->getContent(), $documents),
        );
    }

    public function testTakesTheEntityClassFromTheSource(): void
    {
        $this->entityManager->persist(new Article('First'));
        $this->entityManager->flush();

        $loader = new EntityLoader($this->entityManager);

        $this->assertCount(1, iterator_to_array($loader->load(Article::class), false));
    }

    public function testNarrowsWhatItWalks(): void
    {
        $this->entityManager->persist(new Article('First'));
        $this->entityManager->persist(new Article('Second'));
        $this->entityManager->flush();

        $loader = new EntityLoader($this->entityManager, Article::class);
        $documents = iterator_to_array($loader->load(null, ['criteria' => ['title' => 'Second']]), false);

        $this->assertCount(1, $documents);
        $this->assertSame('Second', $documents[0]->getContent());
    }

    public function testBuildsContentItselfWhenGivenAWayTo(): void
    {
        $this->entityManager->persist(new Article('First'));
        $this->entityManager->flush();

        $loader = new EntityLoader(
            $this->entityManager,
            Article::class,
            static function (object $article): string {
                \assert($article instanceof Article);

                return strtoupper($article->getTitle());
            },
        );

        $this->assertSame('FIRST', iterator_to_array($loader->load(), false)[0]->getContent());
    }

    public function testRendersEntitiesDescribedByAttributes(): void
    {
        $this->entityManager->persist(new Note('Reality TV', 'A show about people.'));
        $this->entityManager->flush();

        $loader = new EntityLoader($this->entityManager, Note::class);
        $documents = iterator_to_array($loader->load(), false);

        $this->assertCount(1, $documents);
        $this->assertSame("Title: Reality TV\nText: A show about people.", $documents[0]->getContent());
        $this->assertStringNotContainsString('never embed this', $documents[0]->getContent());
    }

    public function testUsesTheRendererItWasGiven(): void
    {
        $this->entityManager->persist(new Note('Reality TV'));
        $this->entityManager->flush();

        $propertyAccessor = $this->createMock(PropertyAccessorInterface::class);
        $propertyAccessor->method('getValue')->willReturn('read by the given accessor');

        $loader = new EntityLoader($this->entityManager, Note::class, renderer: new EntityContentRenderer($propertyAccessor));

        $this->assertSame("Title: read by the given accessor\nText: read by the given accessor", iterator_to_array($loader->load(), false)[0]->getContent());
    }

    public function testYieldsNothingForAnEmptyTable(): void
    {
        $loader = new EntityLoader($this->entityManager, Article::class);

        $this->assertSame([], iterator_to_array($loader->load(), false));
    }

    public function testRefusesToLoadWithoutAnEntityClass(): void
    {
        $loader = new EntityLoader($this->entityManager);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No entity class given/');

        iterator_to_array($loader->load(), false);
    }
}
