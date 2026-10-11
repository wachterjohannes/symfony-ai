<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @author Johannes Wachter <wachter.johannes@gmail.com>
 */
final class ArchitectureController extends AbstractController
{
    /**
     * Group label -> the slugs listed under it, in rail order. Together these
     * cover all nine diagrams, and every architecture page renders the whole
     * list, so no page is ever a dead end.
     *
     * The grouping is an editorial claim about what kind of thing each diagram
     * is. It is not a dependency claim: a Requires/Used-by split was tried in
     * an earlier round and reverted, because it implied relationships the
     * manifest data does not support.
     */
    private const array RAIL_GROUPS = [
        'Start here' => ['overview'],
        'Components' => ['platform', 'agent', 'store', 'chat'],
        'Flows' => ['agent-call'],
        'Integrations' => ['ai-bundle', 'mcp-bundle'],
        'Standalone' => ['mate'],
    ];

    /**
     * Slug -> the name the site itself uses for the diagram in the rail and in
     * the mobile bar. Only the overview needs one: its manifest title is a long
     * meta title that wraps in a 260px column and truncates at 390px, and the
     * page it points at is simply called "Architecture". Every other slug keeps
     * the manifest title, which is what its own H1 says too.
     */
    private const array RAIL_TITLES = [
        'overview' => 'Overview',
    ];

    /**
     * Slug -> one line saying what the diagram shows, authored from the
     * manifest's own cards. The rail and the page head read from the same map,
     * so a diagram is described identically wherever it appears.
     */
    private const array SUMMARIES = [
        'overview' => 'How every component fits together',
        'platform' => 'Unified model access across providers',
        'agent' => 'Orchestration and the tool loop',
        'store' => 'Vector storage and retrieval',
        'chat' => 'Conversation glue between Agent and Platform',
        'agent-call' => 'Step by step inside Agent::call()',
        'ai-bundle' => 'Symfony integration for Platform, Agent and Store',
        'mcp-bundle' => 'MCP server and client, official SDK',
        'mate' => 'Standalone CLI coding assistant, no other component required',
    ];

    /**
     * Slug -> the documentation page on symfony.com, shown as a single
     * "Read the docs" link. Every slug has one, so all nine pages carry the
     * same head elements. The two slugs without a page of their own point at
     * the closest real one: the overview at the AI documentation index,
     * agent-call at the Agent component it details.
     */
    private const array DOC_URLS = [
        'overview' => 'https://symfony.com/doc/current/ai/index.html',
        'platform' => 'https://symfony.com/doc/current/ai/components/platform.html',
        'agent' => 'https://symfony.com/doc/current/ai/components/agent.html',
        'agent-call' => 'https://symfony.com/doc/current/ai/components/agent.html',
        'chat' => 'https://symfony.com/doc/current/ai/components/chat.html',
        'store' => 'https://symfony.com/doc/current/ai/components/store.html',
        'mate' => 'https://symfony.com/doc/current/ai/components/mate.html',
        'ai-bundle' => 'https://symfony.com/doc/current/ai/bundles/ai-bundle.html',
        'mcp-bundle' => 'https://symfony.com/doc/current/ai/bundles/mcp-bundle.html',
    ];

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    #[Route('/architecture', name: 'architecture')]
    public function index(): Response
    {
        $diagram = $this->findDiagram('overview');

        return $this->render('architecture/index.html.twig', [
            'diagram' => $diagram,
            'rail' => $this->buildRail($diagram),
            'summary' => self::SUMMARIES['overview'],
            'docUrl' => self::DOC_URLS['overview'],
        ]);
    }

    #[Route('/architecture/{slug}', name: 'architecture_diagram', requirements: ['slug' => '[a-z0-9-]+'])]
    public function diagram(Request $request, string $slug): Response
    {
        // "overview" is the same content as /architecture itself (same artifact,
        // same cross-links); the manifest still lists it as a slug for agents
        // consuming architecture.json, but a human hitting this URL should land
        // on the canonical page rather than a page that links back to its own duplicate.
        if ('overview' === $slug) {
            return $this->redirectToRoute('architecture', [], Response::HTTP_MOVED_PERMANENTLY);
        }

        $diagram = $this->findDiagram($slug);

        $nodeIds = array_column($diagram['nodes'], 'id');
        $viewIds = array_column($diagram['views'], 'id');

        $focus = $request->query->get('focus');
        $focus = \is_string($focus) && \in_array($focus, $nodeIds, true) ? $focus : null;

        $view = $request->query->get('view');
        $view = \is_string($view) && \in_array($view, $viewIds, true) ? $view : null;

        return $this->render('architecture/diagram.html.twig', [
            'diagram' => $diagram,
            'rail' => $this->buildRail($diagram),
            // Both maps cover every slug the manifest has today. The null
            // fallback keeps a diagram added to the manifest before these maps
            // from rendering an empty sentence or an empty href.
            'summary' => self::SUMMARIES[$slug] ?? null,
            'fragment' => $this->buildFragment($focus, $view),
            'docUrl' => self::DOC_URLS[$slug] ?? null,
        ]);
    }

    /**
     * Builds the rail: every diagram, in five authored groups, rendered
     * identically on every architecture route. The rail is the only navigation
     * between diagrams, which is why it is complete rather than derived from
     * the current diagram's neighbourhood.
     *
     * @param array<string, mixed> $diagram
     *
     * @return array{total: int, currentTitle: string, currentPosition: int, hasNeighbours: bool, groups: list<array{label: string, items: list<array{slug: string, title: string, url: string, summary: string, position: int, isCurrent: bool, isNeighbour: bool}>}>}
     */
    private function buildRail(array $diagram): array
    {
        // The overview is connected to nearly every other diagram, so marking
        // its neighbours would mark almost the whole rail and carry no
        // information. Everywhere else the marks are the real graph edges.
        if ('overview' === $diagram['slug']) {
            $neighbours = [];
        } else {
            $neighbours = array_column($this->resolveRelatedDiagrams($diagram), 'slug');
        }

        $position = 0;
        $currentPosition = 0;
        $groups = [];
        foreach (self::RAIL_GROUPS as $label => $slugs) {
            $items = [];
            foreach ($slugs as $slug) {
                ++$position;
                $isCurrent = $slug === $diagram['slug'];
                if ($isCurrent) {
                    $currentPosition = $position;
                }

                $items[] = [
                    'slug' => $slug,
                    'title' => self::RAIL_TITLES[$slug] ?? $this->findDiagram($slug)['title'],
                    // Deliberately built fresh instead of reusing the related
                    // item's url: that one can carry a ?focus=/?view= deep link
                    // meant for one edge, which is wrong for a rail entry.
                    'url' => $this->diagramUrl($slug, null, null),
                    'summary' => self::SUMMARIES[$slug] ?? '',
                    'position' => $position,
                    'isCurrent' => $isCurrent,
                    'isNeighbour' => \in_array($slug, $neighbours, true),
                ];
            }

            $groups[] = ['label' => $label, 'items' => $items];
        }

        return [
            'total' => $position,
            'currentTitle' => self::RAIL_TITLES[$diagram['slug']] ?? $diagram['title'],
            'currentPosition' => $currentPosition,
            'hasNeighbours' => [] !== $neighbours,
            'groups' => $groups,
        ];
    }

    /**
     * A single, deduplicated list of every other diagram connected to this
     * one, outgoing (nodes[].target) or incoming (related[]), regardless of
     * whether the underlying edge is a hard dependency or an optional one.
     * The rail marks exactly this set as "connects to the current diagram".
     *
     * A three-way "Requires"/"Used by"/"Shown in" split was tried and
     * dropped: it required classifying every edge as a real dependency or
     * not, which the data doesn't reliably support (e.g. ai-bundle's edges
     * are almost all "integrates (optional)", not requires).
     * "overview" is dropped: it is the first entry of the rail on every page
     * and the breadcrumb links it too, so marking it adds nothing.
     *
     * @param array<string, mixed> $diagram
     *
     * @return list<array{slug: string, title: string, url: string}>
     */
    private function resolveRelatedDiagrams(array $diagram): array
    {
        $items = $this->resolveTargets($diagram);
        if ('architecture' === $diagram['type']) {
            // diagram.related[] is only the reverse of nodes[].target (a direct,
            // same-diagram link). requires[]/usedBy[] additionally carries the
            // cross-spec dependency graph, which is what resolves a relationship
            // stated one hop away in another diagram (e.g. the overview draws
            // "AI Bundle -> Chat" directly, but neither spec has a node targeting
            // the other): without this, that pair was never marked on either side.
            $items = [...$items, ...$this->resolveSlugs($diagram['related']), ...$this->resolveSlugs($diagram['requires']), ...$this->resolveSlugs($diagram['usedBy'])];
        }

        $seen = [];
        $deduplicated = [];
        foreach ($items as $item) {
            if ('overview' === $item['slug'] || isset($seen[$item['slug']])) {
                continue;
            }
            $seen[$item['slug']] = true;
            $deduplicated[] = $item;
        }

        return $deduplicated;
    }

    /**
     * Resolves diagram.nodes[].target into slug/title/type/url, deduplicated by
     * target slug. A diagram can reference the same target from more than one
     * node (e.g. a direct node plus a grouped-bridges override), which used to
     * produce the same target twice.
     *
     * @param array<string, mixed> $diagram
     *
     * @return list<array{slug: string, title: string, type: string, url: string}>
     */
    private function resolveTargets(array $diagram): array
    {
        $seen = [];
        $targets = [];
        foreach ($diagram['nodes'] as $node) {
            $slug = $node['target'] ?? null;
            if (null === $slug || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $target = $this->findDiagram($slug);
            $targets[] = [
                'slug' => $slug,
                'title' => $target['title'],
                'type' => $target['type'],
                'url' => $this->diagramUrl($slug, $node['targetFocus'] ?? null, $node['targetView'] ?? null),
            ];
        }

        return $targets;
    }

    /**
     * Resolves a plain list of slugs (diagram.related) into slug/title/url
     * triples, so the real title is available instead of a formatted slug,
     * and the "overview" slug routes to its dedicated index page.
     *
     * @param list<string> $slugs
     *
     * @return list<array{slug: string, title: string, url: string}>
     */
    private function resolveSlugs(array $slugs): array
    {
        return array_map($this->diagramRef(...), $slugs);
    }

    /**
     * @return array{slug: string, title: string, url: string}
     */
    private function diagramRef(string $slug): array
    {
        return [
            'slug' => $slug,
            'title' => $this->findDiagram($slug)['title'],
            'url' => $this->diagramUrl($slug, null, null),
        ];
    }

    private function diagramUrl(string $slug, ?string $focus, ?string $view): string
    {
        if ('overview' === $slug) {
            return $this->generateUrl('architecture');
        }

        return $this->generateUrl('architecture_diagram', array_filter([
            'slug' => $slug,
            'focus' => $focus,
            'view' => $view,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function findDiagram(string $slug): array
    {
        foreach ($this->manifest() as $diagram) {
            if ($diagram['slug'] === $slug) {
                return $diagram;
            }
        }

        throw new NotFoundHttpException(\sprintf('No architecture diagram "%s".', $slug));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manifest(): array
    {
        $manifestPath = $this->projectDir.'/public/architecture.json';
        if (!is_file($manifestPath)) {
            throw new \RuntimeException(\sprintf('Architecture manifest "%s" is missing; run "bin/console app:architecture:build".', $manifestPath));
        }

        return json_decode((string) file_get_contents($manifestPath), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function buildFragment(?string $focus, ?string $view): string
    {
        if (null !== $focus) {
            return '#focus='.$focus;
        }

        if (null !== $view) {
            return '#view='.$view;
        }

        return '';
    }
}
