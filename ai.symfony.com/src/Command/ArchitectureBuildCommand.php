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
        // agent-call.sequence.json has its own "runner" participant, so the
        // sequence diagram can open with it already focused instead of
        // landing the reader on an unfocused diagram they have to re-scan.
        'agent:runner' => ['agent-call', 'runner', null],
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

        $this->injectSiteScripts($manifest, $outputDir);

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
     * Injects a small integration layer into every delivered archify artifact,
     * without touching archify's own templates (the artifact is delivered,
     * self-contained HTML, so this edits it after the fact):
     *
     * - hiding the chrome the surrounding page now owns, but only when the
     *   artifact is framed (see framedChromeLayer()),
     * - the "Open <title> diagram" passport link (for nodes with a target),
     * - a corrected "Copy link" that copies the site's own deep-linkable
     *   /architecture/{slug}?focus=<id> URL instead of the bare iframe URL,
     * - moving keyboard/AT focus onto the Semantic Passport when it opens,
     * - removing 3 non-interactive SVG wrapper groups from the Tab order,
     * - rescoping two control labels that claim to act on more than this
     *   one diagram,
     * - a mobile toolbar fix (the fixed-width button rows can overflow their
     *   own bounds on narrow viewports with no visible affordance),
     * - a postMessage listener so the parent page can sync dark/light theme
     *   in place, without reloading the iframe and losing zoom/focus state.
     *
     * @param array<string, array<string, mixed>> $manifest slug => manifest entry
     */
    private function injectSiteScripts(array $manifest, string $outputDir): void
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

            $ownUrl = 'overview' === $slug
                ? $this->urlGenerator->generate('architecture')
                : $this->urlGenerator->generate('architecture_diagram', ['slug' => $slug]);

            $path = $outputDir.'/'.$slug.'.html';
            $html = (string) file_get_contents($path);

            \assert(str_contains($html, '</head>'), \sprintf('"%s" has no closing </head> to inject into.', $path));
            \assert(str_contains($html, '</body>'), \sprintf('"%s" has no closing </body> to inject into.', $path));

            $html = str_replace('</head>', $this->framedChromeLayer().'</head>', $html);
            $html = str_replace('</body>', $this->siteIntegrationScript($ownUrl, $targets).'</body>', $html);

            $filesystem->dumpFile($path, $html);
        }
    }

    /**
     * Hides the chrome the surrounding page now renders itself, but only when
     * the artifact is displayed inside a frame:
     *
     * - `.cards`, which /architecture/{slug} shows beside the diagram,
     * - archify's own diagram title block, which is the page H1 roughly 200px
     *   above the frame,
     * - archify's theme toggle, which duplicates the site header's own toggle
     *   (the site syncs its theme into the frame by postMessage, so both
     *   controls drive the same setting and are visible at once).
     *
     * Together the first two take about 200px out of the inner document, which
     * is what lets the frame fit a laptop viewport without an inner scrollbar.
     * Standalone the artifacts keep all three: they are directly linkable and
     * public/llms.txt points at them, so they have to stand on their own.
     *
     * archify's own `?embed=1` mode is deliberately not reused: it also hides
     * the toolbar, the focus chip, the node finder and the guided views, which
     * is most of what the widget is for.
     *
     * The attribute is set from the document head rather than from the script
     * at the end of the body, so the framed layout is never painted with the
     * removed blocks still in it.
     */
    private function framedChromeLayer(): string
    {
        return <<<'HTML'
            <script>
            (function () {
              // A cross-origin parent makes reading window.top throw, and that
              // only ever happens when the document is framed.
              var framed = true;
              try { framed = window.top !== window.self; } catch (_) {}
              if (framed) document.documentElement.setAttribute('data-sf-ai-framed', 'true');
            })();
            </script>
            <style>
              html[data-sf-ai-framed] .cards,
              html[data-sf-ai-framed] .container > .header,
              html[data-sf-ai-framed] #btn-theme { display: none !important; }

              /* .toolbar is archify's own fixed-position overlay (top:1rem;
                 right:1rem), independent of frame width. It only clears the
                 diagram by accident, when the frame is wide enough that the
                 canvas doesn't reach the frame's actual top-right corner.
                 The persistent rail narrows that frame to ~1100px on desktop,
                 which is close enough to several diagrams' own canvas width
                 that their top-right content (e.g. the overview's "Coding
                 Agent" node) now renders directly under it. Reserving a clear
                 band at the top of the diagram itself, rather than trying to
                 reposition archify's own fixed toolbar, fixes this at any
                 frame width without depending on how wide the rail happens
                 to be. 4.5rem clears a 44px-tall toolbar plus its 1rem offset
                 and margin, measured live at 1440x900.
              */
              html[data-sf-ai-framed] .diagram-container { padding-top: 4.5rem !important; }
            </style>
            HTML;
    }

    /**
     * @param array<string, array{url: string, label: string}> $targets node id => link info
     */
    private function siteIntegrationScript(string $ownUrl, array $targets): string
    {
        $ownUrlJson = json_encode($ownUrl, \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES);
        $targetsJson = json_encode($targets, \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES);

        return <<<HTML
            <style>
              /* The toolbar is a fixed-width row of ~8 buttons; on narrow viewports
                 it can be wider than the diagram itself, and archify's own
                 overflow-x:auto scroll on that row has no visible affordance, so
                 the zoom controls can sit off-screen with nothing to suggest
                 scrolling. Wrapping onto a second line keeps every control on
                 screen without needing to touch archify's own layout logic. */
              @media (max-width: 480px) {
                .diagram-nav {
                  flex-wrap: wrap !important;
                  overflow: visible !important;
                  justify-content: flex-end !important;
                  row-gap: 2px !important;
                }

                /* The toolbar (theme, style, motion, Present, Export) is a
                   second, separate row with the same problem and a worse
                   symptom: below 720px archify gives it width:max-content and
                   justify-content:flex-end, so once its content is wider than
                   the viewport the overflow goes off the *left* edge, where
                   nothing can scroll it back. Measured at 400px, its first
                   button sat at x=-45. Dropping max-content lets it wrap. */
                .toolbar {
                  flex-wrap: wrap !important;
                  width: auto !important;
                  max-width: 100% !important;
                  justify-content: flex-end !important;
                  row-gap: 4px !important;
                }
              }
            </style>
            <script>
            (() => {
              const ownUrl = {$ownUrlJson};
              const targets = {$targetsJson};
              const idEl = document.getElementById('focus-id');
              const meta = document.getElementById('focus-passport-meta');
              const passportCard = document.getElementById('focus-chip');
              const copyBtn = document.getElementById('btn-focus-copy');

              // ---- "Open <title> diagram" passport link -----------------------
              if (idEl && meta && meta.parentNode && Object.keys(targets).length > 0) {
                const link = document.createElement('a');
                link.className = 'sf-ai-passport-link';
                link.rel = 'noopener';
                link.style.cssText = 'display:none;margin:0.35rem 0 0.6rem;font-weight:600;color:var(--arrow-emphasis,#0969da);text-decoration:underline;cursor:pointer;';
                meta.insertAdjacentElement('afterend', link);

                const updateLink = () => {
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

                new MutationObserver(updateLink).observe(idEl, {childList: true, characterData: true, subtree: true});
                updateLink();
              }

              // ---- Fix: "Copy link" must copy a real site URL ------------------
              // archify's own handler copies location.href (the bare, chrome-less
              // /diagrams/<slug>.html artifact URL) with a #focus= hash. Swap it
              // for the site's own ?focus= deep link, which actually has a way
              // back (header, breadcrumb, Explore chips).
              if (copyBtn && idEl && window.Archify && window.Archify.focus
                  && typeof window.Archify.focus.copyLink === 'function') {
                copyBtn.removeEventListener('click', window.Archify.focus.copyLink);

                const fallbackCopy = (text) => {
                  const field = document.createElement('textarea');
                  field.value = text;
                  field.setAttribute('readonly', '');
                  field.style.position = 'fixed';
                  field.style.opacity = '0';
                  document.body.appendChild(field);
                  field.select();
                  let copied = false;
                  try { copied = document.execCommand('copy'); } catch (_) {}
                  field.remove();
                  return copied;
                };

                copyBtn.addEventListener('click', () => {
                  const nodeId = idEl.textContent.trim();
                  if (!nodeId) return;

                  let url;
                  try { url = new URL(ownUrl, window.top.location.origin); }
                  catch (_) { url = new URL(ownUrl, window.location.origin); }
                  url.searchParams.set('focus', nodeId);
                  const value = url.toString();

                  const finish = (copied) => {
                    copyBtn.textContent = copied ? 'Copied' : 'Copy failed';
                    copyBtn.setAttribute('aria-label', copied
                      ? 'Focused node link copied'
                      : 'Could not copy focused node link');
                    window.setTimeout(() => {
                      copyBtn.textContent = 'Copy link';
                      copyBtn.setAttribute('aria-label', 'Copy link to focused node');
                    }, 1600);
                  };

                  const copy = navigator.clipboard && typeof navigator.clipboard.writeText === 'function'
                    ? navigator.clipboard.writeText(value).then(() => true).catch(() => fallbackCopy(value))
                    : Promise.resolve(fallbackCopy(value));
                  copy.then(finish);
                });
              }

              // ---- Fix: move keyboard/AT focus onto the passport when it opens -
              // #focus-chip is role="region" with no tabindex and no aria-live, so
              // opening it (a click, or a ?focus= deep link on arrival) leaves
              // focus on the node and the panel undiscoverable by keyboard/AT
              // without a long linear search through the rest of the diagram.
              if (passportCard) {
                if (!passportCard.hasAttribute('tabindex')) {
                  passportCard.setAttribute('tabindex', '-1');
                }
                passportCard.setAttribute('aria-live', 'polite');

                const focusPassport = () => window.requestAnimationFrame(() => {
                  try { passportCard.focus({preventScroll: false}); }
                  catch (_) { passportCard.focus(); }
                });

                let wasHidden = passportCard.hidden;
                new MutationObserver(() => {
                  const isHidden = passportCard.hidden;
                  if (wasHidden && !isHidden) focusPassport();
                  wasHidden = isHidden;
                }).observe(passportCard, {attributes: true, attributeFilter: ['hidden']});

                // A `?focus=` deep link is resolved by archify's own bootstrap
                // before this script runs, so the panel can already be open by
                // the time `wasHidden` above is captured: that first open never
                // fires the observer above, it only catches later transitions.
                if (!wasHidden) focusPassport();
              }

              // ---- Fix: remove dead Tab stops ----------------------------------
              // The root <svg role="img"> and its "Direct relationship explorer"
              // / "Semantic legend" wrapper <g role="group"|"toolbar"> elements
              // report tabIndex -1 (the default for an element with no explicit
              // tabindex), but Chromium still lands sequential Tab focus on them;
              // only an explicit tabindex="-1" reliably excludes them.
              const svgRoot = document.querySelector('svg[role="img"]');
              if (svgRoot) svgRoot.setAttribute('tabindex', '-1');
              document.querySelectorAll('svg g[role="group"], svg g[role="toolbar"]').forEach((group) => {
                group.setAttribute('tabindex', '-1');
              });

              // ---- Fix: two controls that overstate their own reach -----------
              // "MAP" and the node finder are the most search-shaped controls
              // in the feature, so a reader who cannot find something reaches
              // for them first. Both only ever see the one diagram in this
              // frame, and the site now has nine, so the reader concludes the
              // content does not exist. Rename them after what they do.
              // archify re-applies both labels from its own i18n table every
              // time the panels open or close, so patching that table is what
              // makes the rename stick; the DOM pass below only covers the
              // labels that are already rendered.
              const labels = {
                'viewer.nav.radar': 'Open the minimap of this diagram',
                'viewer.nav.radar.title': 'Minimap of this diagram (M)',
                'viewer.nav.radar.short': 'MINIMAP',
                'viewer.radar.title': 'Minimap of this diagram',
                'viewer.radar.close': 'Close the minimap of this diagram',
                'viewer.radar.openFull': 'Open the full minimap of this diagram',
                'viewer.radar.open': 'Expand',
                'viewer.radar.focus': 'Focus {label} from the minimap',
                'viewer.nav.find': 'Find a node in this diagram',
                'viewer.nav.find.title': 'Find a node in this diagram (/)',
                'viewer.finder.title': 'Find a node in this diagram',
              };

              if (window.archifyI18nData && window.archifyI18nData.messages) {
                Object.assign(window.archifyI18nData.messages, labels);
              }

              const setText = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
              };
              const setLabel = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.setAttribute('aria-label', text);
              };

              // The node finder's button holds an icon, not a text label, so
              // only its accessible name and tooltip change.
              setText('btn-overview-map', labels['viewer.nav.radar.short']);
              setLabel('btn-overview-map', labels['viewer.nav.radar']);
              setLabel('btn-node-finder', labels['viewer.nav.find']);
              setText('overview-map-title', labels['viewer.radar.title']);
              setText('node-finder-title', labels['viewer.finder.title']);
              setText('overview-map-expand', labels['viewer.radar.open']);
              setLabel('overview-map-expand', labels['viewer.radar.openFull']);
              setLabel('overview-map-close', labels['viewer.radar.close']);

              const mapBtn = document.getElementById('btn-overview-map');
              if (mapBtn) mapBtn.title = labels['viewer.nav.radar.title'];
              const findBtn = document.getElementById('btn-node-finder');
              if (findBtn) findBtn.title = labels['viewer.nav.find.title'];

              // ---- Fix: theme sync without reloading the iframe ----------------
              // The parent page sets ?theme= only once, on the iframe's initial
              // load (a later reload would destroy zoom/focus/passport state), so
              // it posts a message instead when the site theme changes afterwards.
              window.addEventListener('message', (event) => {
                if (event.origin !== window.location.origin) return;
                const data = event.data;
                if (!data || data.type !== 'sf-ai-set-theme') return;
                const desired = 'dark' === data.theme ? 'dark' : 'light';
                const current = document.documentElement.getAttribute('data-theme');
                if (current !== desired && window.Archify && window.Archify.theme
                    && typeof window.Archify.theme.toggle === 'function') {
                  window.Archify.theme.toggle();
                }
              });
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
