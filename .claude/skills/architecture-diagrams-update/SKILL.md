---
name: architecture-diagrams-update
description: "Updates the navigable architecture diagrams on ai.symfony.com (/architecture) by scanning the Symfony AI monorepo for current components, bridges, and package dependencies, then editing and rebuilding the archify diagram specs under docs/architecture/. Use this skill whenever the user asks to update, regenerate, or refresh the architecture diagrams, mentions keeping them in sync with the codebase, or when app:architecture:check reports drift. Also trigger on 'update the architecture diagrams', 'add a diagram for X', 'the diagrams are out of date'."
---

# Architecture Diagrams Updater for Symfony AI

This skill keeps the navigable architecture diagrams on `ai.symfony.com/architecture` in sync
with the actual codebase. The diagrams are rendered by [archify](https://github.com/tt-a1i/archify),
vendored read-only under `tools/archify/`, from hand-curated JSON specs under `docs/architecture/`.

## Why this matters

Diagrams that drift from the code are worse than no diagrams: they actively mislead. Unlike
`llms-txt-update`, this is not a full mechanical regeneration — archify's showcase quality bar
(≤ 12 primary nodes, 0 warnings) means the specs are curated, not auto-extracted. This skill's
job is to find drift, then make the smallest correct edit to the affected spec.

## Step 1 — Find drift first

Before editing anything:

```bash
cd ai.symfony.com && php bin/console app:architecture:check
```

This compares every spec under `docs/architecture/` against the real codebase (package list,
`require`/`require-dev` edges between `src/*/composer.json` files, bridge directories, and every
`sources[]` path referenced in a spec) and reports:

- **Errors** — a spec references a file, package, or bridge directory that no longer exists. Fix
  these, they are always real.
- **Warnings** — new code exists that no diagram mentions yet (a new `src/*` package, a new
  bridge directory). Not automatically wrong: decide per case whether it belongs in an existing
  diagram, a new one, or is genuinely out of scope for the current diagram set.

## What to scan when updating a spec

1. **Package dependencies** — `src/*/composer.json`, `require` only for drawn edges; `require-dev`
   entries for `symfony/ai-*` are integrations, not dependency edges (see the existing
   `ai-bundle` node for the established pattern: "integrates (optional)", not a solid line).
2. **Bridge directories** — `src/*/src/Bridge/*`. Always modeled as ONE grouped node per
   component (e.g. "Platform Bridges — 37 providers"), never one node per bridge — the 12-node
   budget cannot absorb them individually.
3. **Component descriptions and pipeline shape** — `src/*/AGENTS.md`, the best source for node
   labels and card text.
4. **Layer boundaries** — `deptrac.yaml` machine-checks where a component ends and its `Bridge/`
   namespace begins; useful to cross-check node scope.
5. **Runtime behavior** (for `sequence`/`workflow`/`lifecycle` specs) — read the actual source
   (e.g. `src/agent/src/Agent.php`, `src/agent/src/Execution/Runner.php` for the agent call flow),
   never infer control flow from a docblock alone. Verify concrete facts like default limits
   (`maxToolCalls`) in the constructor, not from memory or prior diagrams.

## Editing a spec

1. Read the current file under `docs/architecture/<slug>.<type>.json` and the matching schema:
   `tools/archify/schemas/<type>.schema.json` + `tools/archify/schemas/common.schema.json`.
2. Keep `meta.repository.revision` current: set it to `git rev-parse HEAD` when you actually
   change the spec's facts (not on every unrelated commit — it means "last verified against this
   SHA", not "last touched").
3. Preserve existing `sources[]` references that are still valid; add new ones for new facts.
4. Respect the authoring invariants from `tools/archify/SKILL.md`: one obvious main path, ≤ 12
   primary nodes, no unexplained geometry controls (`via`, `channelX`, `labelAt`) unless a
   diagnosed validation failure calls for one.
5. Cross-links matter as much as the diagram content: if a node represents another diagram's
   subject (e.g. a "Platform" node in the overview diagram), it must have a matching `target` in
   the diagram's manifest entry so the cross-link chip on the website actually links there — see
   `ai.symfony.com/src/Command/ArchitectureBuildCommand.php` for the manifest generation logic.

## Validate and rebuild

```bash
node tools/archify/bin/archify.mjs validate <type> docs/architecture/<slug>.<type>.json --quality showcase --json
```

Iterate until 9/9 checks, 0 errors, 0 warnings — a 4-check receipt is not showcase acceptance.
Then rebuild everything (this re-renders every spec, regenerates the public manifest, and writes
the delivered HTML artifacts):

```bash
cd ai.symfony.com && php bin/console app:architecture:build
```

Re-run `app:architecture:check` to confirm the drift you were fixing is gone and nothing else
regressed.

## Adding a new diagram

1. Pick the type from archify's router: `architecture` for components/services, `sequence` for
   call chains, `workflow` for processes, `dataflow` for pipelines, `lifecycle` for state machines.
2. Add `docs/architecture/<new-slug>.<type>.json`.
3. Add a `target`/`related` entry on whichever existing diagram should link to it (the manifest is
   generated, but the target/label conventions live in the source specs and
   `ArchitectureBuildCommand`'s label-to-slug matching — check both).
4. `app:architecture:build`, then open `http://127.0.0.1:8002/architecture/<new-slug>` locally
   (start with `symfony server:start` in `ai.symfony.com` if not already running) and check the
   desktop viewport invariant: `document.documentElement.scrollWidth <= window.innerWidth` and
   `scrollHeight <= window.innerHeight` at 1440×900, 1600×1000, and 1920×1080, with the page's
   real surrounding chrome (header, breadcrumb, cross-links), not just the raw archify artifact.

## Important notes

- **Never edit `~/.agents/skills/archify` directly.** It is the shared, project-agnostic
  installation used across other projects too. All Symfony-AI-specific changes (the `symfony-ai`
  visual preset, any future patch) live only in the vendored `tools/archify/` copy.
- `docs/architecture/` was deliberately chosen over `ai.symfony.com/config/architecture/` so the
  specs sit next to the rest of the project's documentation; verified safe against `doctor-rst`
  and the `docs-builder` run, both ignore non-`.rst` files there.
- The generated `ai.symfony.com/public/architecture.json` is the machine-readable manifest meant
  for other agents/tools to consume (referenced from `public/llms.txt`); it is regenerated by
  `app:architecture:build`, never hand-edited.
- Always commit specs and the regenerated artifacts (`public/diagrams/*.html`,
  `public/architecture.json`) together: production (Upsun) ships only `ai.symfony.com/` and cannot
  run the generator.
- Do not invent facts to fill out a diagram. Every node/edge should trace back to something you
  actually read in `composer.json`, a bridge directory listing, or real source code.
