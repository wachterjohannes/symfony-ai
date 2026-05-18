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

use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\Exception\GraphProviderException;

/**
 * Per-token JSON cache for request-scoped runtime graphs.
 *
 * Cache layout: <cacheDir>/ai_mate/graph/request/<token>.json. Profiler tokens are immutable
 * once written, so no content-hash is needed — the token itself is the key. Writes are atomic
 * via temp-file + rename so a half-written cache cannot poison subsequent reads.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class RequestGraphCache
{
    public function __construct(
        private readonly string $cacheDir,
    ) {
    }

    public function load(string $token): ?RuntimeGraph
    {
        $path = $this->cacheFilePath($token);
        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($payload) || ($payload['token'] ?? null) !== $token) {
            return null;
        }

        $graph = new RuntimeGraph();
        foreach ((array) ($payload['nodes'] ?? []) as $node) {
            if (!\is_array($node) || !isset($node['id'], $node['type'], $node['label'])) {
                continue;
            }
            $graph->addNode(new GraphNode(
                (string) $node['id'],
                (string) $node['type'],
                (string) $node['label'],
                \is_array($node['metadata'] ?? null) ? $node['metadata'] : [],
            ));
        }
        foreach ((array) ($payload['edges'] ?? []) as $edge) {
            if (!\is_array($edge) || !isset($edge['from'], $edge['relation'], $edge['to'])) {
                continue;
            }
            $graph->addEdge(new GraphEdge(
                (string) $edge['from'],
                (string) $edge['relation'],
                (string) $edge['to'],
                \is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [],
            ));
        }

        return $graph;
    }

    public function save(string $token, RuntimeGraph $graph): void
    {
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            $nodes[] = [
                'id' => $node->id,
                'type' => $node->type,
                'label' => $node->label,
                'metadata' => $node->metadata,
            ];
        }

        $edges = [];
        foreach ($graph->edges() as $edge) {
            $edges[] = [
                'from' => $edge->from,
                'relation' => $edge->relation,
                'to' => $edge->to,
                'metadata' => $edge->metadata,
            ];
        }

        $payload = [
            'token' => $token,
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        $path = $this->cacheFilePath($token);
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new GraphProviderException(\sprintf('Failed to create request graph cache directory "%s".', $dir));
        }

        $encoded = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $tmp = $path.'.tmp';
        if (false === @file_put_contents($tmp, $encoded)) {
            throw new GraphProviderException(\sprintf('Failed to write request graph cache to "%s".', $tmp));
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new GraphProviderException(\sprintf('Failed to atomically rename request graph cache "%s" → "%s".', $tmp, $path));
        }
    }

    private function cacheFilePath(string $token): string
    {
        return $this->cacheDir.'/ai_mate/graph/request/'.$token.'.json';
    }
}
