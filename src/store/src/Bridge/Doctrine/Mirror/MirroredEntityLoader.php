<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Mirror;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\AI\Store\Bridge\Doctrine\EntityContentRenderer;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Loads Doctrine entities marked with #[AiEmbeddable] and renders each record into a RAG-ready document.
 *
 * The source passed to {@see load()} is the fully-qualified entity class name. Each record is rendered into a
 * single {@see TextDocument} with a deterministic identifier derived from the entity's Doctrine identifier, so
 * re-indexing an entity upserts the same document rather than duplicating it.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class MirroredEntityLoader implements LoaderInterface
{
    /**
     * Fixed namespace (RFC 4122 DNS namespace) used to derive deterministic, upsert-friendly document identifiers.
     */
    private const ID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private readonly EntityContentRenderer $renderer;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?EntityContentRenderer $renderer = null,
    ) {
        $this->renderer = $renderer ?? new EntityContentRenderer();
    }

    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('MirroredEntityLoader requires an entity class name as source, null given.');
        }

        if (!class_exists($source)) {
            throw new InvalidArgumentException(\sprintf('Entity class "%s" does not exist.', $source));
        }

        foreach ($this->entityManager->getRepository($source)->findAll() as $entity) {
            yield $this->loadEntity($entity);
        }
    }

    /**
     * Renders a single managed entity into a document with a deterministic identifier.
     *
     * Besides the human-readable source, the document carries structured identity metadata (entity class and
     * identifier map) so the {@see EntityHydrator} can resolve retrieval results back to entities.
     */
    public function loadEntity(object $entity): TextDocument
    {
        $content = $this->renderer->render($entity);

        if ('' === trim($content)) {
            throw new InvalidArgumentException(\sprintf('Rendering entity "%s" produced no content. Declare at least one #[AiEmbeddableField] with a non-empty value or provide a template.', $entity::class));
        }

        $classMetadata = $this->entityManager->getClassMetadata($entity::class);
        $identifiers = $this->identifierValuesFor($entity);

        $metadata = new Metadata([
            Metadata::KEY_SOURCE => $this->sourceFor($entity),
        ]);

        if ([] !== $identifiers) {
            $metadata->offsetSet(EntityHydrator::METADATA_ENTITY_CLASS, $classMetadata->getName());
            $metadata->offsetSet(EntityHydrator::METADATA_ENTITY_IDENTIFIER, array_map(
                fn (mixed $value): bool|float|int|string => \is_scalar($value) ? $value : $this->renderer->stringify($value),
                $identifiers,
            ));
        }

        $title = $this->renderer->titleFor($entity);
        if (null !== $title) {
            $metadata->setTitle($title);
        }

        return new TextDocument($this->documentIdFor($entity), $content, $metadata);
    }

    /**
     * Deterministic document identifier for an entity, stable across re-indexing (enables upserts and removals).
     */
    public function documentIdFor(object $entity): Uuid
    {
        return Uuid::v5(Uuid::fromString(self::ID_NAMESPACE), $this->sourceFor($entity));
    }

    private function sourceFor(object $entity): string
    {
        $classMetadata = $this->entityManager->getClassMetadata($entity::class);
        $identifiers = $this->identifierValuesFor($entity);

        $identifier = implode('-', array_map(
            fn (mixed $value): string => $this->renderer->stringify($value),
            $identifiers,
        ));

        if ('' === $identifier) {
            $identifier = spl_object_hash($entity);
        }

        return \sprintf('%s#%s', $classMetadata->getName(), $identifier);
    }

    /**
     * Identifier values of the entity, with identifier associations (e.g. #[ORM\Id] on a ManyToOne) resolved
     * recursively to the scalar identifier value of the associated entity, so the metadata stays deterministic
     * and can be fed back into the database by the {@see EntityHydrator}.
     *
     * @return array<string, mixed> field => value map, empty when the identifier is not (fully) available
     */
    private function identifierValuesFor(object $entity): array
    {
        return $this->flattenIdentifierValues($this->entityManager->getClassMetadata($entity::class), $entity);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     *
     * @return array<string, mixed>
     */
    private function flattenIdentifierValues(ClassMetadata $classMetadata, object $entity): array
    {
        $values = $classMetadata->getIdentifierValues($entity);

        $flattened = [];
        foreach ($values as $field => $value) {
            if (\is_object($value) && $classMetadata->hasAssociation($field)) {
                $target = $this->entityManager->getClassMetadata($classMetadata->getAssociationTargetClass($field));
                $nested = $this->flattenIdentifierValues($target, $value);
                if (1 !== \count($nested)) {
                    // the associated entity has no (or a composite) identifier of its own -> the identity of
                    // this entity cannot be represented as a flat field => value map
                    return [];
                }
                $value = array_values($nested)[0];
            }

            $flattened[$field] = $value;
        }

        return $flattened;
    }
}
