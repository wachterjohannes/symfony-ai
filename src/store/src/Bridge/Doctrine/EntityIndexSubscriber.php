<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\IndexerInterface;
use Symfony\AI\Store\StoreInterface;

/**
 * Keeps the vector store in sync with #[AiEmbeddable] entities: re-indexes them on persist/update and removes
 * them on delete. Each record maps to exactly one document (no chunking), so re-indexing upserts by the
 * deterministic identifier produced by {@see DoctrineEntityLoader::documentIdFor()}.
 *
 * For write-heavy applications, wrap the indexing call in an asynchronous Messenger message to move embedding
 * off the request/flush path.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class EntityIndexSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly DoctrineEntityLoader $loader,
        private readonly IndexerInterface $indexer,
        private readonly StoreInterface $store,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::postRemove,
        ];
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->reindex($args->getObject());
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->reindex($args->getObject());
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->isEmbeddable($entity)) {
            return;
        }

        $this->store->remove((string) $this->loader->documentIdFor($entity));
    }

    private function reindex(object $entity): void
    {
        if (!$this->isEmbeddable($entity)) {
            return;
        }

        $this->indexer->index($this->loader->loadEntity($entity));
    }

    private function isEmbeddable(object $entity): bool
    {
        return [] !== (new \ReflectionClass($entity))->getAttributes(AiEmbeddable::class);
    }
}
