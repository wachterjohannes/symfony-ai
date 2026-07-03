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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\VectorDocument;

/**
 * Hydrates retrieval results back into managed Doctrine entities.
 *
 * Documents written by {@see DoctrineEntityLoader} carry structured identity metadata (entity class and
 * identifier map), so a semantic query against the vector store can be resolved back to the actual database
 * records. Entities are batch-loaded per class (a single IN() query for single-column identifiers) while the
 * ranking order of the incoming documents is preserved in the returned list.
 *
 * Documents that cannot be hydrated are skipped silently: they carry no identity metadata (they were not
 * indexed from an entity), the referenced entity no longer exists in the database (stale index entry, e.g. a
 * record deleted while the vector store was not in sync), or the identity metadata no longer matches the
 * current mapping (the class is no longer a mapped entity, or the identifier fields changed since indexing).
 * Pass an $onSkippedDocument callback to observe such documents, e.g. to log them or queue a cleanup of the
 * store.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class DoctrineEntityHydrator
{
    /**
     * Metadata key holding the fully-qualified class name of the originating entity.
     */
    public const METADATA_ENTITY_CLASS = '_entity_class';

    /**
     * Metadata key holding the entity's Doctrine identifier as a field => value map.
     */
    public const METADATA_ENTITY_IDENTIFIER = '_entity_identifier';

    private readonly ?\Closure $onSkippedDocument;

    /**
     * @param (callable(EmbeddableDocumentInterface|VectorDocument): void)|null $onSkippedDocument invoked for every
     *                                                                                             document that cannot
     *                                                                                             be hydrated
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?callable $onSkippedDocument = null,
    ) {
        $this->onSkippedDocument = null === $onSkippedDocument ? null : $onSkippedDocument(...);
    }

    /**
     * Resolves documents back to managed entities, preserving the incoming (ranking) order.
     *
     * @param iterable<EmbeddableDocumentInterface|VectorDocument> $documents
     *
     * @return list<object> managed entities in the order of the given documents
     */
    public function hydrate(iterable $documents): array
    {
        $plan = [];
        $identifiersByClass = [];

        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $class = $metadata[self::METADATA_ENTITY_CLASS] ?? null;
            $identifier = $metadata[self::METADATA_ENTITY_IDENTIFIER] ?? null;

            if (!\is_string($class) || !class_exists($class) || !\is_array($identifier) || [] === $identifier) {
                $this->skip($document);
                continue;
            }

            $plan[] = ['document' => $document, 'class' => $class, 'key' => $this->keyFor($identifier)];
            $identifiersByClass[$class][] = $identifier;
        }

        $entitiesByClass = [];
        foreach ($identifiersByClass as $class => $identifiers) {
            $entitiesByClass[$class] = $this->loadEntities($class, $identifiers);
        }

        $entities = [];
        foreach ($plan as $item) {
            $entity = $entitiesByClass[$item['class']][$item['key']] ?? null;

            if (null === $entity) {
                $this->skip($item['document']);
                continue;
            }

            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Batch-loads the entities of one class and keys them by their canonical identifier.
     *
     * @param class-string               $class
     * @param list<array<string, mixed>> $identifiers
     *
     * @return array<string, object>
     */
    private function loadEntities(string $class, array $identifiers): array
    {
        try {
            $classMetadata = $this->entityManager->getClassMetadata($class);
        } catch (MappingException|PersistenceMappingException) {
            // the class exists but is not a mapped entity (stale metadata from an older mapping, or a store
            // shared with a foreign application) -> every document referencing it is skipped
            return [];
        }

        $identifierFields = $classMetadata->getIdentifierFieldNames();

        if (1 === \count($identifierFields)) {
            $loaded = $this->loadBySingleIdentifier($class, $identifierFields[0], $identifiers);
        } else {
            $loaded = $this->loadByCompositeIdentifier($class, $identifierFields, $identifiers);
        }

        $keyed = [];
        foreach ($loaded as $entity) {
            $keyed[$this->keyFor($this->identifierValues($classMetadata, $entity))] = $entity;
        }

        return $keyed;
    }

    /**
     * @param class-string               $class
     * @param list<array<string, mixed>> $identifiers
     *
     * @return list<object>
     */
    private function loadBySingleIdentifier(string $class, string $field, array $identifiers): array
    {
        $values = [];
        foreach ($identifiers as $identifier) {
            if (\array_key_exists($field, $identifier)) {
                $values[$this->stringifyValue($identifier[$field])] = $identifier[$field];
            }
        }

        if ([] === $values) {
            return [];
        }

        return array_values($this->entityManager->getRepository($class)->findBy([$field => array_values($values)]));
    }

    /**
     * Composite identifiers cannot be batched into a portable IN() query, so each tuple is resolved individually
     * through the identity-map-aware {@see EntityManagerInterface::find()}. Tuples that do not carry exactly the
     * entity's current identifier fields (stale metadata after an identifier remapping) are skipped instead of
     * being passed to find(), which would throw.
     *
     * @param class-string               $class
     * @param list<string>               $identifierFields
     * @param list<array<string, mixed>> $identifiers
     *
     * @return list<object>
     */
    private function loadByCompositeIdentifier(string $class, array $identifierFields, array $identifiers): array
    {
        $loaded = [];
        $seen = [];
        foreach ($identifiers as $identifier) {
            if (!$this->matchesIdentifierFields($identifier, $identifierFields)) {
                continue;
            }

            $key = $this->keyFor($identifier);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $entity = $this->entityManager->find($class, $identifier);
            if (null !== $entity) {
                $loaded[] = $entity;
            }
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $identifier
     * @param list<string>         $identifierFields
     */
    private function matchesIdentifierFields(array $identifier, array $identifierFields): bool
    {
        if (\count($identifier) !== \count($identifierFields)) {
            return false;
        }

        foreach ($identifierFields as $field) {
            if (!\array_key_exists($field, $identifier)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Identifier values of a loaded entity, with identifier associations resolved to their scalar identifier
     * values so they match the metadata written by {@see DoctrineEntityLoader}.
     *
     * @param ClassMetadata<object> $classMetadata
     *
     * @return array<string, mixed>
     */
    private function identifierValues(ClassMetadata $classMetadata, object $entity): array
    {
        $values = $classMetadata->getIdentifierValues($entity);

        foreach ($values as $field => $value) {
            if (\is_object($value) && $classMetadata->hasAssociation($field)) {
                $target = $this->entityManager->getClassMetadata($classMetadata->getAssociationTargetClass($field));
                $nested = $this->identifierValues($target, $value);
                if (1 === \count($nested)) {
                    $values[$field] = array_values($nested)[0];
                }
            }
        }

        return $values;
    }

    /**
     * Canonical string representation of an identifier map, stable across value types (e.g. an integer identifier
     * read back as string from a JSON-based store still matches).
     *
     * @param array<string, mixed> $identifier
     */
    private function keyFor(array $identifier): string
    {
        ksort($identifier);

        $parts = [];
        foreach ($identifier as $field => $value) {
            $parts[] = $field.'='.$this->stringifyValue($value);
        }

        return implode('|', $parts);
    }

    private function stringifyValue(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (\is_object($value)) {
            return spl_object_hash($value);
        }

        return '';
    }

    private function skip(EmbeddableDocumentInterface|VectorDocument $document): void
    {
        if (null !== $this->onSkippedDocument) {
            ($this->onSkippedDocument)($document);
        }
    }
}
