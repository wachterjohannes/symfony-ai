<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Attribute;

/**
 * Marks a Doctrine entity as embeddable, i.e. renderable into a Symfony AI Store RAG document.
 *
 * Placed on the entity class. Only entities carrying this attribute are handled by the
 * {@see \Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityLoader} and the
 * {@see \Symfony\AI\Store\Bridge\Doctrine\EntityIndexSubscriber}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AiEmbeddable
{
    /**
     * @param string|null $template An optional rendering template. Placeholders in the form "{propertyName}" are
     *                              replaced with the corresponding (property-path readable) value. When null, the
     *                              document content is generated from every property marked with #[AiEmbeddableField].
     * @param string|null $title    name of the property whose value is stored as the document title in metadata
     */
    public function __construct(
        public readonly ?string $template = null,
        public readonly ?string $title = null,
    ) {
    }
}
