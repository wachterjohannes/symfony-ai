<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Capability;

/**
 * Shared output envelope for graph-aware MCP tools (`symfony-context`, future
 * `symfony-inspect`, future `symfony-operation`).
 *
 * Each field is intentionally simple to keep encoding cheap and the schema stable across
 * tools. Tools serialise via {@see toArray()} → {@see \Symfony\AI\Mate\Encoding\ResponseEncoder::encode()}.
 *
 * Documented metadata conventions per node type (advisory, not enforced):
 *  - service:    {class?: ?class-string, tags?: list<string>, tagMetadata?: list<array{name: string, attributes: array<string, string>}>, aliasOf?: string}
 *  - route:      {path?: string, methods?: list<string>, defaults?: array<string, mixed>}
 *  - controller: {class?: class-string}
 *  - interface:  {} (no canonical metadata today)
 *
 * @author Johannes Wachter <johannes@sulu.io>
 *
 * @phpstan-type ServiceMetadata array{class?: ?string, tags?: list<string>, tagMetadata?: list<array{name: string, attributes: array<string, string>}>, aliasOf?: string}
 * @phpstan-type RouteMetadata array{path?: string, methods?: list<string>, defaults?: array<string, mixed>}
 */
final readonly class GraphResult
{
    /**
     * @param list<array{id: string, type: string, label: string, metadata?: array<string, mixed>}> $primaryNodes
     * @param list<string>                                                                          $findings
     * @param list<array{kind: string, from?: string, relation?: string, to?: string, note?: string}> $evidence
     * @param list<array{id: string, type: string, label?: string, metadata?: array<string, mixed>}> $relatedNodes
     * @param list<array{tool: string, args: array<string, mixed>}>                                  $nextActions
     * @param list<string>                                                                          $warnings
     */
    public function __construct(
        public string $summary,
        public array $primaryNodes,
        public array $findings,
        public array $evidence,
        public array $relatedNodes,
        public array $nextActions,
        public array $warnings = [],
    ) {
    }

    /**
     * @return array{
     *     summary: string,
     *     primaryNodes: list<array<string, mixed>>,
     *     findings: list<string>,
     *     evidence: list<array<string, mixed>>,
     *     relatedNodes: list<array<string, mixed>>,
     *     nextActions: list<array<string, mixed>>,
     *     warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'primaryNodes' => $this->primaryNodes,
            'findings' => $this->findings,
            'evidence' => $this->evidence,
            'relatedNodes' => $this->relatedNodes,
            'nextActions' => $this->nextActions,
            'warnings' => $this->warnings,
        ];
    }
}
