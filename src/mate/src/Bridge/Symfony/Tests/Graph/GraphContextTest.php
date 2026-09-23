<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphContext;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class GraphContextTest extends TestCase
{
    public function testDefaults()
    {
        $context = new GraphContext();

        $this->assertNull($context->profilerToken);
        $this->assertNull($context->route);
        $this->assertNull($context->service);
        $this->assertSame([], $context->scopes);
        $this->assertSame(2, $context->depth);
    }

    public function testCarriesValues()
    {
        $context = new GraphContext(
            profilerToken: 'abc123',
            route: 'app_checkout',
            service: 'App\\Service\\CheckoutService',
            scopes: ['dependencies', 'consumers'],
            depth: 4,
        );

        $this->assertSame('abc123', $context->profilerToken);
        $this->assertSame('app_checkout', $context->route);
        $this->assertSame('App\\Service\\CheckoutService', $context->service);
        $this->assertSame(['dependencies', 'consumers'], $context->scopes);
        $this->assertSame(4, $context->depth);
    }
}
