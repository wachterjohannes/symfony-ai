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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityLoader;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Movie;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\PlainEntity;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Product;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class DoctrineEntityLoaderTest extends TestCase
{
    public function testLoadEntityRendersDeclaredFieldsAndExcludesUndeclaredProperties()
    {
        $product = new Product(42, 'Cast-iron pan', 39.9);
        $loader = new DoctrineEntityLoader($this->createEntityManager(static fn (): array => ['id' => 42]));

        $document = $loader->loadEntity($product);

        $this->assertInstanceOf(TextDocument::class, $document);
        $this->assertStringContainsString('Name: Cast-iron pan', $document->getContent());
        $this->assertStringContainsString('Price in EUR: 39.9', $document->getContent());
        $this->assertStringNotContainsString('secret-hash', $document->getContent());
        $this->assertSame('Cast-iron pan', $document->getMetadata()->getTitle());
        $this->assertSame(Product::class.'#42', $document->getMetadata()->getSource());
    }

    public function testLoadEntityUsesTemplateWhenProvided()
    {
        $movie = new Movie(7, 'Inception', 2010);
        $loader = new DoctrineEntityLoader($this->createEntityManager(static fn (): array => ['id' => 7]));

        $document = $loader->loadEntity($movie);

        $this->assertSame('The movie "Inception" was released in 2010.', $document->getContent());
        $this->assertSame('Inception', $document->getMetadata()->getTitle());
    }

    public function testDocumentIdIsDeterministicAndUpsertFriendly()
    {
        $product = new Product(42, 'Cast-iron pan', 39.9);
        $loader = new DoctrineEntityLoader($this->createEntityManager(static fn (): array => ['id' => 42]));

        $id = $loader->documentIdFor($product);

        $this->assertInstanceOf(Uuid::class, $id);
        $this->assertSame((string) $id, (string) $loader->loadEntity($product)->getId());
        $this->assertSame((string) $id, (string) $loader->documentIdFor(new Product(42, 'Renamed', 10.0)));
    }

    public function testLoadIteratesTheRepository()
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn([new Product(1, 'A', 1.0), new Product(2, 'B', 2.0)]);

        $loader = new DoctrineEntityLoader($this->createEntityManager(
            static fn (object $entity): array => ['id' => $entity->id],
            $repository,
        ));

        $documents = iterator_to_array($loader->load(Product::class));

        $this->assertCount(2, $documents);
        $this->assertSame(Product::class.'#1', $documents[0]->getMetadata()->getSource());
        $this->assertSame(Product::class.'#2', $documents[1]->getMetadata()->getSource());
    }

    public function testLoadRejectsNullSource()
    {
        $loader = new DoctrineEntityLoader($this->createMock(EntityManagerInterface::class));

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($loader->load(null));
    }

    public function testLoadEntityRejectsUnmarkedEntity()
    {
        $loader = new DoctrineEntityLoader($this->createMock(EntityManagerInterface::class));

        $this->expectException(InvalidArgumentException::class);

        $loader->loadEntity(new PlainEntity());
    }

    /**
     * @param callable(object): array<string, mixed> $identifierResolver
     * @param EntityRepository<object>|null          $repository
     */
    private function createEntityManager(callable $identifierResolver, ?EntityRepository $repository = null): EntityManagerInterface
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getIdentifierValues')->willReturnCallback($identifierResolver);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);

        if (null !== $repository) {
            $entityManager->method('getRepository')->willReturn($repository);
        }

        return $entityManager;
    }
}
