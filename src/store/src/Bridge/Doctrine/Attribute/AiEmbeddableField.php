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
 * Marks a property of an #[AiEmbeddable] entity as part of the rendered document content.
 *
 * By opting fields in explicitly, sensitive columns (password hashes, tokens, PII) are never embedded
 * or sent to the model unless they are deliberately declared.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AiEmbeddableField
{
    /**
     * @param string|null $label Human-readable label used when rendering this field into generated prose.
     *                           Defaults to a humanized version of the property name.
     */
    public function __construct(
        public readonly ?string $label = null,
    ) {
    }
}
