<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Graph;

/**
 * A single node in the runtime graph.
 *
 * Node IDs follow stable, human-readable conventions:
 *   route:app_checkout
 *   controller:App\Controller\CheckoutController::__invoke
 *   service:App\Service\CheckoutService
 *   interface:Symfony\Component\EventDispatcher\EventDispatcherInterface
 *   request:abc123
 *   query:abc123:17
 *   doc:symfony:service_container/autowiring
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class GraphNode
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public array $metadata = [],
    ) {
    }
}
