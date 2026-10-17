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

use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Renders the text of an entity marked with #[AiEmbeddable].
 *
 * Both ways of using this bridge describe an entity through it, so the same attributes work for the
 * Store keeping vectors on the entity's table and for the Mirror keeping them in another store.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class EntityContentRenderer
{
    private readonly PropertyAccessorInterface $propertyAccessor;

    /**
     * @var array<class-string, array{template: string|null, title: string|null, fields: list<array{property: string, label: string}>}>
     */
    private array $definitions = [];

    public function __construct(?PropertyAccessorInterface $propertyAccessor = null)
    {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    /**
     * @param object|class-string $entityOrClass
     */
    public function supports(object|string $entityOrClass): bool
    {
        return [] !== (new \ReflectionClass($entityOrClass))->getAttributes(AiEmbeddable::class);
    }

    /**
     * @throws InvalidArgumentException if the entity is not marked with #[AiEmbeddable] or declares nothing to render
     */
    public function render(object $entity): string
    {
        $definition = $this->definitionFor($entity::class);

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

    /**
     * The value of the property named by {@see AiEmbeddable::$title}, if there is one.
     */
    public function titleFor(object $entity): ?string
    {
        $property = $this->definitionFor($entity::class)['title'];

        if (null === $property || !$this->propertyAccessor->isReadable($entity, $property)) {
            return null;
        }

        $title = $this->stringify($this->propertyAccessor->getValue($entity, $property));

        return '' === $title ? null : $title;
    }

    public function stringify(mixed $value): string
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

    /**
     * @param class-string $class
     *
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

    private function humanize(string $property): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', $property) ?? $property;
        $spaced = str_replace(['_', '-'], ' ', $spaced);

        return ucfirst(strtolower(trim($spaced)));
    }
}
