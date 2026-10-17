<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Symfony\AI\Platform\Vector\VectorInterface;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;

/**
 * Describes itself through attributes instead of EmbeddableEntityInterface, and keeps its vector on its own table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'note')]
#[AiEmbeddable(title: 'title')]
class Note
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    /** @phpstan-ignore property.onlyRead (Doctrine assigns the generated id by reflection) */
    private int $id;

    #[ORM\Column(type: 'string')]
    #[AiEmbeddableField]
    private string $title;

    #[ORM\Column(type: 'string', nullable: true)]
    #[AiEmbeddableField(label: 'Text')]
    private ?string $body;

    #[ORM\Column(type: 'string')]
    private string $internalComment = 'never embed this';

    #[ORM\Column(type: 'vector', nullable: true)]
    private ?VectorInterface $embedding = null;

    public function __construct(string $title, ?string $body = null)
    {
        $this->title = $title;
        $this->body = $body;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getEmbedding(): ?VectorInterface
    {
        return $this->embedding;
    }

    public function setEmbedding(?VectorInterface $embedding): void
    {
        $this->embedding = $embedding;
    }

    public function getInternalComment(): string
    {
        return $this->internalComment;
    }
}
