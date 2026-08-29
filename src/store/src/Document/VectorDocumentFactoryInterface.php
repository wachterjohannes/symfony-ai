<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Document;

use Symfony\AI\Platform\Vector\VectorInterface;

/**
 * An embeddable document that decides which vector document it turns into once vectorized.
 *
 * Without this interface a VectorizerInterface pairs the embeddable document's id and metadata with
 * the computed vector in a plain VectorDocument, which drops everything else the document carried.
 * Implementing it keeps that context - a Doctrine entity, an aggregate, a file handle - attached to
 * the vector all the way into the store.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface VectorDocumentFactoryInterface extends EmbeddableDocumentInterface
{
    public function createVectorDocument(VectorInterface $vector): VectorDocumentInterface;
}
