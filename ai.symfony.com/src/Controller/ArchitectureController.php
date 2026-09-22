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
     * A first-time visitor has no mental model of how the eight diagrams
     * relate; this is the suggested starting path, surfaced as a single
     * sentence on the overview page (index 0 is "overview" itself, so the
     * suggestion starts at index 1).
     */
    private const array READING_ORDER = [
        'overview', 'platform', 'agent', 'agent-call', 'store', 'chat', 'ai-bundle', 'mcp-bundle',
    ];

    /**
     * Slug -> the component/bundle documentation page on symfony.com, shown
     * as a single "Read the docs" link, distinct from the internal cross-links.
     */
    private const array DOC_URLS = [
        'platform' => 'https://symfony.com/doc/current/ai/components/platform.html',
        'agent' => 'https://symfony.com/doc/current/ai/components/agent.html',
        'chat' => 'https://symfony.com/doc/current/ai/components/chat.html',
        'store' => 'https://symfony.com/doc/current/ai/components/store.html',
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
            'explore' => $this->resolveRelatedDiagrams($diagram),
            'firstStop' => $this->diagramRef(self::READING_ORDER[1]),
            'secondStop' => $this->diagramRef(self::READING_ORDER[2]),
        ]);
    }

    #[Route('/architecture/{slug}', name: 'architecture_diagram', requirements: ['slug' => '[a-z0-9-]+'])]
    public function diagram(Request $request, string $slug): Response
    {
        $diagram = $this->findDiagram($slug);

        $nodeIds = array_column($diagram['nodes'], 'id');
        $viewIds = array_column($diagram['views'], 'id');

        $focus = $request->query->get('focus');
        $focus = \is_string($focus) && \in_array($focus, $nodeIds, true) ? $focus : null;

        $view = $request->query->get('view');
        $view = \is_string($view) && \in_array($view, $viewIds, true) ? $view : null;

        return $this->render('architecture/diagram.html.twig', [
            'diagram' => $diagram,
            'groups' => [['label' => 'Explore', 'items' => $this->resolveRelatedDiagrams($diagram)]],
            'fragment' => $this->buildFragment($focus, $view),
            'docUrl' => self::DOC_URLS[$slug] ?? null,
        ]);
    }

    /**
     * A single, deduplicated list of every other diagram connected to this
     * one, outgoing (nodes[].target) or incoming (related[]), regardless of
     * whether the underlying edge is a hard dependency or an optional one.
     *
     * A three-way "Requires"/"Used by"/"Shown in" split was tried and
     * dropped: it required classifying every edge as a real dependency or
     * not, which the data doesn't reliably support (e.g. ai-bundle's edges
     * are almost all "integrates (optional)", not requires), and having two
     * different chip vocabularies across the eight pages (this one plus the
     * overview/agent-call's flat "Explore") confused more than it clarified.
     * "overview" is dropped: the breadcrumb above already says
     * "Home / Architecture / <title>", a chip repeating that adds nothing.
     *
     * @param array<string, mixed> $diagram
     *
     * @return list<array{slug: string, title: string, url: string}>
     */
    private function resolveRelatedDiagrams(array $diagram): array
    {
        $items = $this->resolveTargets($diagram);
        if ('architecture' === $diagram['type']) {
            $items = [...$items, ...$this->resolveSlugs($diagram['related'])];
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
     * render as two chips carrying two different labelling rules for the same
     * URL: one took the source node's own label, the other the target's title.
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
     * triples, so a chip shows the real title instead of a formatted slug,
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
