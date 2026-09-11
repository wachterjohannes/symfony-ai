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
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
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
final class DoctrineEntityLoader implements LoaderInterface
{
    /**
     * Fixed namespace (RFC 4122 DNS namespace) used to derive deterministic, upsert-friendly document identifiers.
     */
    private const ID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private readonly PropertyAccessorInterface $propertyAccessor;

    /**
     * @var array<class-string, array{template: string|null, title: string|null, fields: list<array{property: string, label: string}>}>
     */
    private array $definitions = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?PropertyAccessorInterface $propertyAccessor = null,
    ) {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('DoctrineEntityLoader requires an entity class name as source, null given.');
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
     */
    public function loadEntity(object $entity): TextDocument
    {
        $definition = $this->definitionFor($entity::class);

        $content = $this->render($entity, $definition);

        if ('' === trim($content)) {
            throw new InvalidArgumentException(\sprintf('Rendering entity "%s" produced no content; declare at least one #[AiEmbeddableField] with a non-empty value or provide a template.', $entity::class));
        }

        $metadata = new Metadata([
            Metadata::KEY_SOURCE => $this->sourceFor($entity),
        ]);

        $title = $this->titleFor($entity, $definition);
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

    /**
     * @return array{template: string|null, title: string|null, fields: list<array{property: string, label: string}>}
     */
    private function definitionFor(string $class): array
    {
        if (isset($this->definitions[$class])) {
            return $this->definitions[$class];
        }

        $reflection = new \ReflectionClass($class);

        $classAttributes = $reflection->getAttributes(AiEmbeddable::class);
        if ([] === $classAttributes) {
            throw new InvalidArgumentException(\sprintf('Entity "%s" is not marked with #[AiEmbeddable].', $class));
        }
        $embeddable = $classAttributes[0]->newInstance();

        $fields = [];
        foreach ($reflection->getProperties() as $property) {
            $fieldAttributes = $property->getAttributes(AiEmbeddableField::class);
            if ([] === $fieldAttributes) {
                continue;
            }
            $field = $fieldAttributes[0]->newInstance();
            $fields[] = [
                'property' => $property->getName(),
                'label' => $field->label ?? $this->humanize($property->getName()),
            ];
        }

        if (null === $embeddable->template && [] === $fields) {
            throw new InvalidArgumentException(\sprintf('Entity "%s" must declare a template or at least one #[AiEmbeddableField] property.', $class));
        }

        return $this->definitions[$class] = [
            'template' => $embeddable->template,
            'title' => $embeddable->title,
            'fields' => $fields,
        ];
    }

    /**
     * @param array{template: string|null, title: string|null, fields: list<array{property: string, label: string}>} $definition
     */
    private function render(object $entity, array $definition): string
    {
        if (null !== $definition['template']) {
            return $this->renderTemplate($entity, $definition['template']);
        }

        $lines = [];
        foreach ($definition['fields'] as $field) {
            $value = $this->stringify($this->propertyAccessor->getValue($entity, $field['property']));
            if ('' === $value) {
                continue;
            }
            $lines[] = \sprintf('%s: %s', $field['label'], $value);
        }

        return implode("\n", $lines);
    }

    private function renderTemplate(object $entity, string $template): string
    {
        $rendered = preg_replace_callback(
            '/\{([a-zA-Z0-9_.]+)\}/',
            function (array $matches) use ($entity): string {
                $path = $matches[1];
                if (!$this->propertyAccessor->isReadable($entity, $path)) {
                    return $matches[0];
                }

                return $this->stringify($this->propertyAccessor->getValue($entity, $path));
            },
            $template,
        );

        return $rendered ?? $template;
    }

    /**
     * @param array{template: string|null, title: string|null, fields: list<array{property: string, label: string}>} $definition
     */
    private function titleFor(object $entity, array $definition): ?string
    {
        if (null === $definition['title']) {
            return null;
        }

        if (!$this->propertyAccessor->isReadable($entity, $definition['title'])) {
            return null;
        }

        $title = $this->stringify($this->propertyAccessor->getValue($entity, $definition['title']));

        return '' === $title ? null : $title;
    }

    private function sourceFor(object $entity): string
    {
        $classMetadata = $this->entityManager->getClassMetadata($entity::class);
        $identifiers = $classMetadata->getIdentifierValues($entity);

        $identifier = implode('-', array_map(
            fn (mixed $value): string => $this->stringify($value),
            $identifiers,
        ));

        if ('' === $identifier) {
            $identifier = spl_object_hash($entity);
        }

        return \sprintf('%s#%s', $entity::class, $identifier);
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (\is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->stringify($item), $value));
        }

        return '';
    }

    private function humanize(string $property): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', $property) ?? $property;
        $spaced = str_replace(['_', '-'], ' ', $spaced);

        return ucfirst(strtolower(trim($spaced)));
    }
}
