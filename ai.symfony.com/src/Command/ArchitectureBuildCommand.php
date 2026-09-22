<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Renders the archify diagram specifications under docs/architecture/ into
 * standalone HTML artifacts under public/diagrams/, and regenerates the
 * manifest that drives cross-links between them.
 *
 * @author Johannes Wachter <wachter.johannes@gmail.com>
 */
#[AsCommand(
    name: 'app:architecture:build',
    description: 'Render the architecture diagram specifications with archify',
)]
final readonly class ArchitectureBuildCommand
{
    /**
     * "<owning diagram slug>:<node id>" -> [target slug, target focus node id,
     * target guided-view id]. Only needed when a node's own label does not
     * already match another diagram's slug. Keyed by the owning diagram, not
     * just the node id, because node ids are only unique per diagram: e.g.
     * "store-bridges" is both the node overview.architecture.json overrides
     * *and* store.architecture.json's own bridges node, which must not
     * override to itself.
     */
    private const array TARGET_OVERRIDES = [
        'overview:platform-bridges' => ['platform', 'bridges', null],
        'overview:store-bridges' => ['store', 'store-bridges', null],
        'overview:agent-tools' => ['agent', null, 'toolbox'],
        'agent:runner' => ['agent-call', null, null],
    ];

    /**
     * Spec filename suffixes archify understands, in the order the build walks them.
     */
    private const array DIAGRAM_TYPES = ['architecture', 'workflow', 'sequence', 'dataflow', 'lifecycle'];

    public function __construct(
        private string $projectDir,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $repoDir = \dirname($this->projectDir);
        $archifyBin = $this->locateArchifyBin($repoDir);

        if (null === $archifyBin) {
            $io->error('archify was not found. Set ARCHIFY_HOME, vendor it into "tools/archify", or install it under "~/.agents/skills/archify".');

            return Command::FAILURE;
        }

        $specDir = $repoDir.'/docs/architecture';
        $outputDir = $this->projectDir.'/public/diagrams';

        $specs = [];
        foreach (self::DIAGRAM_TYPES as $diagramType) {
            $specs = [...$specs, ...(glob($specDir.'/*.'.$diagramType.'.json') ?: [])];
        }
        sort($specs);

        if ([] === $specs) {
            $io->warning(\sprintf('No diagram specifications found in "%s".', $specDir));

            return Command::SUCCESS;
        }

        $io->title('Build architecture diagrams');

        $diagrams = [];
        foreach ($specs as $spec) {
            [$slug, $type] = $this->parseSpecFilename($spec);
            $output = $outputDir.'/'.$slug.'.html';

            $io->section($slug);

            // Repository-verified sources[] only exist on architecture diagrams.
            $repoRootArgs = 'architecture' === $type ? ['--repo-root', $repoDir] : [];

            if (!$this->run($io, [$archifyBin, 'validate', $type, $spec, '--quality', 'showcase', ...$repoRootArgs, '--json'], $repoDir)) {
                return Command::FAILURE;
            }

            if (!$this->run($io, [$archifyBin, 'deliver', $type, $spec, $output, '--quality', 'showcase', ...$repoRootArgs, '--json'], $repoDir)) {
                return Command::FAILURE;
            }

            $io->writeln(\sprintf('  -> %s', $output));

            $decoded = json_decode((string) file_get_contents($spec), true, flags: \JSON_THROW_ON_ERROR);
            $diagrams[$slug] = $decoded;
        }

        // Public, not config/: an agent or script reading the site should be able to
        // fetch the architecture manifest the same way it would fetch llms.txt.
        $manifestPath = $this->projectDir.'/public/architecture.json';
        $manifest = $this->buildManifest($diagrams, $manifestPath);
        $io->writeln(\sprintf('  -> %s', $manifestPath));

        $this->injectPassportLinks($manifest, $outputDir);

        $io->success(\sprintf('Rendered %d diagram(s).', \count($specs)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array<string, mixed>> $diagrams slug => decoded spec
     *
     * @return array<string, array<string, mixed>> slug => manifest entry
     */
    private function buildManifest(array $diagrams, string $manifestPath): array
    {
        $slugs = array_keys($diagrams);

        $manifest = [];
        foreach ($diagrams as $slug => $spec) {
            // "components" (architecture diagrams) and "participants" (sequence
            // diagrams) both describe the boxes a diagram shows; neither schema
            // has both, but node-shaped output is what the cross-link resolution
            // and the manifest's consumers expect regardless of diagram type.
            $rawNodes = $spec['components'] ?? $spec['participants'] ?? [];

            $nodes = [];
            foreach ($rawNodes as $component) {
                $id = $component['id'];
                [$target, $targetFocus, $targetView] = $this->resolveTarget($id, $component['label'], $slug, $slugs);

                $node = ['id' => $id, 'label' => $component['label']];
                if (null !== $target) {
                    $node['target'] = $target;
                }
                if (null !== $targetFocus) {
                    $node['targetFocus'] = $targetFocus;
                }
                if (null !== $targetView) {
                    $node['targetView'] = $targetView;
                }

                $nodes[] = $node;
            }

            $views = array_map(
                static fn (array $view): array => ['id' => $view['id'], 'label' => $view['label']],
                $spec['meta']['views'] ?? [],
            );

            $cards = array_map(
                static fn (array $card): array => ['title' => $card['title'], 'items' => $card['items']],
                $spec['cards'] ?? [],
            );

            $manifest[$slug] = [
                'slug' => $slug,
                'type' => $spec['diagram_type'],
                'title' => $spec['meta']['title'],
                'artifact' => 'diagrams/'.$slug.'.html',
                'nodes' => $nodes,
                'views' => $views,
                'cards' => $cards,
                'related' => [],
                'requires' => [],
                'usedBy' => [],
            ];

            // Sequence/workflow/dataflow/lifecycle specs have no meta.repository
            // (only architecture diagrams carry repository-verified sources[]).
            // Omit the key rather than ship a misleading "revision": null.
            if (isset($spec['meta']['repository']['revision'])) {
                $manifest[$slug]['revision'] = $spec['meta']['repository']['revision'];
            }
        }

        // related[] is the reverse of nodes[].target: which other diagrams point here.
        foreach ($manifest as $fromSlug => $diagram) {
            foreach ($diagram['nodes'] as $node) {
                $toSlug = $node['target'] ?? null;
                if (null !== $toSlug && $toSlug !== $fromSlug && isset($manifest[$toSlug]) && !\in_array($fromSlug, $manifest[$toSlug]['related'], true)) {
                    $manifest[$toSlug]['related'][] = $fromSlug;
                }
            }
        }

        // requires[]/usedBy[] are structural dependency edges, distinct from
        // nodes[].target: a target only says "a node with this label exists
        // somewhere in the spec", regardless of which way an arrow points, so
        // it cannot tell "Agent requires Platform" apart from "Chat requires
        // Agent, drawn on Agent's own page for context". This instead walks
        // connections[].from/to and keeps an edge only when both endpoints
        // resolve to a known diagram slug. A single diagram's own topology is
        // often too indirect for this (agent -> runner -> platform is two
        // hops), so edges are collected across every architecture spec and
        // merged: the overview diagram (and others) restate the same
        // relationship directly, one hop away.
        $edges = [];
        foreach ($diagrams as $spec) {
            if ('architecture' !== $spec['diagram_type']) {
                continue;
            }

            $labelById = array_column($spec['components'], 'label', 'id');
            foreach ($spec['connections'] ?? [] as $connection) {
                $fromSlug = $this->slugify($labelById[$connection['from']] ?? '');
                $toSlug = $this->slugify($labelById[$connection['to']] ?? '');

                if ($fromSlug === $toSlug || !isset($manifest[$fromSlug]) || !isset($manifest[$toSlug])) {
                    continue;
                }

                $edges[$fromSlug.'>'.$toSlug] = [$fromSlug, $toSlug];
            }
        }

        foreach ($edges as [$fromSlug, $toSlug]) {
            if (!\in_array($toSlug, $manifest[$fromSlug]['requires'], true)) {
                $manifest[$fromSlug]['requires'][] = $toSlug;
            }
            if (!\in_array($fromSlug, $manifest[$toSlug]['usedBy'], true)) {
                $manifest[$toSlug]['usedBy'][] = $fromSlug;
            }
        }

        $filesystem = new Filesystem();
        $filesystem->dumpFile(
            $manifestPath,
            json_encode(array_values($manifest), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );

        return $manifest;
    }

    /**
     * Clicking a node in a delivered diagram opens archify's own "Semantic
     * Passport" panel (source links, reach counts), but does not navigate
     * anywhere — the only way to a node's own detail diagram was previously
     * the separate "Explore" chip row below the iframe. This adds a small
     * "Open <title> diagram" link inside that panel itself, for nodes that
     * have a target diagram, without touching archify's own templates: the
     * link is injected into the already-delivered, self-contained HTML.
     *
     * @param array<string, array<string, mixed>> $manifest slug => manifest entry
     */
    private function injectPassportLinks(array $manifest, string $outputDir): void
    {
        $filesystem = new Filesystem();

        foreach ($manifest as $slug => $diagram) {
            $targets = [];
            foreach ($diagram['nodes'] as $node) {
                if (!isset($node['target'])) {
                    continue;
                }

                $targets[$node['id']] = [
                    'url' => 'overview' === $node['target']
                        ? $this->urlGenerator->generate('architecture')
                        : $this->urlGenerator->generate('architecture_diagram', array_filter([
                            'slug' => $node['target'],
                            'focus' => $node['targetFocus'] ?? null,
                            'view' => $node['targetView'] ?? null,
                        ])),
                    'label' => $manifest[$node['target']]['title'] ?? $node['label'],
                ];
            }

            if ([] === $targets) {
                continue;
            }

            $path = $outputDir.'/'.$slug.'.html';
            $html = (string) file_get_contents($path);
            $script = $this->passportLinkScript($targets);

            \assert(str_contains($html, '</body>'), \sprintf('"%s" has no closing </body> to inject into.', $path));
            $filesystem->dumpFile($path, str_replace('</body>', $script.'</body>', $html));
        }
    }

    /**
     * @param array<string, array{url: string, label: string}> $targets node id => link info
     */
    private function passportLinkScript(array $targets): string
    {
        $json = json_encode($targets, \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES);

        return <<<HTML
            <script>
            (() => {
              const targets = {$json};
              const idEl = document.getElementById('focus-id');
              const meta = document.getElementById('focus-passport-meta');
              if (!idEl || !meta || !meta.parentNode) return;

              const link = document.createElement('a');
              link.className = 'sf-ai-passport-link';
              link.rel = 'noopener';
              link.style.cssText = 'display:none;margin:0.35rem 0 0.6rem;font-weight:600;color:var(--arrow-emphasis,#0969da);text-decoration:underline;cursor:pointer;';
              meta.insertAdjacentElement('afterend', link);

              const update = () => {
                const info = targets[idEl.textContent.trim()];
                if (!info) {
                  link.style.display = 'none';
                  return;
                }
                link.href = info.url;
                link.textContent = 'Open ' + info.label + ' diagram →';
                link.style.display = 'block';
              };
              link.addEventListener('click', (event) => {
                event.preventDefault();
                window.top.location.href = link.href;
              });

              new MutationObserver(update).observe(idEl, {childList: true, characterData: true, subtree: true});
              update();
            })();
            </script>
            HTML;
    }

    /**
     * Splits "agent-call.sequence.json" into its slug ("agent-call") and archify type ("sequence").
     *
     * @return array{0: string, 1: string}
     */
    private function parseSpecFilename(string $specPath): array
    {
        $filename = basename($specPath, '.json');
        $lastDot = strrpos($filename, '.');

        \assert(false !== $lastDot, \sprintf('Spec filename "%s" does not end in ".<type>.json".', $specPath));

        return [substr($filename, 0, $lastDot), substr($filename, $lastDot + 1)];
    }

    /**
     * @param list<string> $knownSlugs
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} target slug, target focus node id, target guided-view id
     */
    private function resolveTarget(string $nodeId, string $label, string $ownSlug, array $knownSlugs): array
    {
        if (isset(self::TARGET_OVERRIDES[$ownSlug.':'.$nodeId])) {
            return self::TARGET_OVERRIDES[$ownSlug.':'.$nodeId];
        }

        $candidate = $this->slugify($label);

        if ($candidate !== $ownSlug && \in_array($candidate, $knownSlugs, true)) {
            return [$candidate, null, null];
        }

        return [null, null, null];
    }

    private function slugify(string $label): string
    {
        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $label)), '-');
    }

    /**
     * @param list<string> $command
     */
    private function run(SymfonyStyle $io, array $command, string $cwd): bool
    {
        $process = new Process($command, $cwd, ['ARCHIFY_UPDATE_CHECK_DISABLED' => '1'], timeout: 120);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error(\sprintf("%s failed:\n%s", $command[1], $process->getErrorOutput().$process->getOutput()));

            return false;
        }

        return true;
    }

    private function locateArchifyBin(string $repoDir): ?string
    {
        $candidates = array_filter([
            false !== ($env = getenv('ARCHIFY_HOME')) ? $env.'/bin/archify.mjs' : null,
            $repoDir.'/tools/archify/bin/archify.mjs',
            ($home = getenv('HOME')) ? $home.'/.agents/skills/archify/bin/archify.mjs' : null,
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
