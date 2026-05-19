<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Operation;

use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;

/**
 * Contract for a named, deterministic multi-step recipe over the runtime graph tools.
 *
 * Recipes are config, not new business logic. Each `run()` call composes the existing
 * `symfony-context` / `symfony-graph` / `symfony-inspect` plumbing via {@see GraphToolBox}
 * and aggregates the per-step results into a single {@see GraphResult} for the agent.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface OperationRecipeInterface
{
    /**
     * Recipe identifier. Must be stable across releases — agents reference recipes by name.
     */
    public function name(): string;

    /**
     * Short human-readable description surfaced in the symfony-operation tool's discovery
     * output. Keep it under ~120 characters so it renders well in chat clients.
     */
    public function description(): string;

    /**
     * @param array<string, mixed> $args Recipe-specific arguments (e.g. `{target: latest_request}`)
     */
    public function run(array $args, GraphToolBox $tools): GraphResult;
}
