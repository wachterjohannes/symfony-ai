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
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityLoader;
use Symfony\AI\Store\Bridge\Doctrine\EntityIndexSubscriber;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\PlainEntity;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Product;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\IndexerInterface;
use Symfony\AI\Store\StoreInterface;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class EntityIndexSubscriberTest extends TestCase
{
    public function testPostPersistIndexesEmbeddableEntity()
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('index')->with($this->isInstanceOf(TextDocument::class));

        $store = $this->createMock(StoreInterface::class);
        $store->expects($this->never())->method('remove');

        $subscriber = new EntityIndexSubscriber($this->createLoader(), $indexer, $store);
        $subscriber->postPersist($this->createEvent(new Product(42, 'Cast-iron pan', 39.9)));
    }

    public function testPostUpdateReindexesEmbeddableEntity()
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('index')->with($this->isInstanceOf(TextDocument::class));

        $subscriber = new EntityIndexSubscriber($this->createLoader(), $indexer, $this->createMock(StoreInterface::class));
        $subscriber->postUpdate($this->createEvent(new Product(42, 'Cast-iron pan', 39.9)));
    }

    public function testPostRemoveRemovesByDeterministicId()
    {
        $product = new Product(42, 'Cast-iron pan', 39.9);
        $loader = $this->createLoader();
        $expectedId = (string) $loader->documentIdFor($product);

        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->never())->method('index');

        $store = $this->createMock(StoreInterface::class);
        $store->expects($this->once())->method('remove')->with($expectedId);

        $subscriber = new EntityIndexSubscriber($loader, $indexer, $store);
        $subscriber->postRemove($this->createEvent($product));
    }

    public function testNonEmbeddableEntityIsIgnored()
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->never())->method('index');

        $store = $this->createMock(StoreInterface::class);
        $store->expects($this->never())->method('remove');

        $subscriber = new EntityIndexSubscriber($this->createLoader(), $indexer, $store);
        $subscriber->postPersist($this->createEvent(new PlainEntity()));
        $subscriber->postRemove($this->createEvent(new PlainEntity()));
    }

    public function testSubscribesToLifecycleEvents()
    {
        $subscriber = new EntityIndexSubscriber(
            $this->createLoader(),
            $this->createMock(IndexerInterface::class),
            $this->createMock(StoreInterface::class),
        );

        $this->assertSame(['postPersist', 'postUpdate', 'postRemove'], $subscriber->getSubscribedEvents());
    }

    private function createLoader(): DoctrineEntityLoader
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getIdentifierValues')->willReturnCallback(static fn (object $entity): array => ['id' => $entity->id]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);

        return new DoctrineEntityLoader($entityManager);
    }

    /**
     * @return LifecycleEventArgs<ObjectManager>
     */
    private function createEvent(object $entity): LifecycleEventArgs
    {
        return new LifecycleEventArgs($entity, $this->createMock(ObjectManager::class));
    }
}
