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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;
use Symfony\AI\Store\Bridge\Doctrine\EntityContentRenderer;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Movie;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\PlainEntity;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Product;
use Symfony\AI\Store\Exception\InvalidArgumentException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class EntityContentRendererTest extends TestCase
{
    public function testRendersOnlyDeclaredFields(): void
    {
        $content = (new EntityContentRenderer())->render(new Product(42, 'Cast-iron pan', 39.9));

        $this->assertSame("Name: Cast-iron pan\nPrice in EUR: 39.9", $content);
    }

    public function testRendersTheTemplateWhenThereIsOne(): void
    {
        $content = (new EntityContentRenderer())->render(new Movie(7, 'Inception', 2010));

        $this->assertSame('The movie "Inception" was released in 2010.', $content);
    }

    public function testKeepsPlaceholdersItCannotRead(): void
    {
        $entity = new #[AiEmbeddable(template: '{name} and {missing}')] class {
            public string $name = 'Pan';
        };

        $this->assertSame('Pan and {missing}', (new EntityContentRenderer())->render($entity));
    }

    public function testSkipsEmptyFields(): void
    {
        $entity = new #[AiEmbeddable] class {
            #[AiEmbeddableField]
            public ?string $firstName = 'Ada';

            #[AiEmbeddableField]
            public ?string $nickname = null;
        };

        $this->assertSame('First name: Ada', (new EntityContentRenderer())->render($entity));
    }

    public function testReadsTheTitle(): void
    {
        $renderer = new EntityContentRenderer();

        $this->assertSame('Cast-iron pan', $renderer->titleFor(new Product(42, 'Cast-iron pan', 39.9)));
        $this->assertNull($renderer->titleFor(new #[AiEmbeddable(template: 'x')] class {
        }));
    }

    public function testKnowsWhichEntitiesItSupports(): void
    {
        $renderer = new EntityContentRenderer();

        $this->assertTrue($renderer->supports(new Product(1, 'Pan', 1.0)));
        $this->assertTrue($renderer->supports(Product::class));
        $this->assertFalse($renderer->supports(new PlainEntity()));
    }

    public function testRefusesAnUnmarkedEntity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not marked with #\[AiEmbeddable\]/');

        (new EntityContentRenderer())->render(new PlainEntity());
    }

    public function testRefusesAnEntityDeclaringNothingToRender(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must declare a template or at least one/');

        (new EntityContentRenderer())->render(new #[AiEmbeddable] class {
        });
    }

    #[DataProvider('provideValues')]
    public function testStringifiesValues(mixed $value, string $expected): void
    {
        $this->assertSame($expected, (new EntityContentRenderer())->stringify($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideValues(): iterable
    {
        yield 'null' => [null, ''];
        yield 'true' => [true, 'yes'];
        yield 'false' => [false, 'no'];
        yield 'int' => [3, '3'];
        yield 'date' => [new \DateTimeImmutable('2026-01-02T03:04:05+00:00'), '2026-01-02T03:04:05+00:00'];
        yield 'enum' => [EntityContentRendererStatus::Open, 'open'];
        yield 'stringable' => [new class implements \Stringable {
            public function __toString(): string
            {
                return 'text';
            }
        }, 'text'];
        yield 'list' => [['a', null, true], 'a, , yes'];
        yield 'object' => [new \stdClass(), ''];
    }
}

enum EntityContentRendererStatus: string
{
    case Open = 'open';
}
