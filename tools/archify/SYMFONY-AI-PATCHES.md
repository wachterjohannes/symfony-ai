# Symfony AI patches

This is a vendored copy of [archify](https://github.com/tt-a1i/archify) (MIT), pinned at
`2.17.0-dev.1` per `package.json`, with three local patches on top to add a `symfony-ai`
visual preset. **Do not apply these patches to the shared `~/.agents/skills/archify`
installation** — that copy is used across other, unrelated projects.

## Patched files

- `schemas/common.schema.json` — `visualPreset` enum extended with `"symfony-ai"`.
- `assets/template.html` — two new CSS blocks, `[data-preset="symfony-ai"][data-theme="dark"]`
  and `[data-preset="symfony-ai"][data-theme="light"]`, following the same variable set as the
  existing `signal-flow`/`blueprint`/`editorial` presets. Colors are derived from the
  `--sf-ai-*` custom properties in `ai.symfony.com/assets/styles/app.css`. No decorative
  overlays or preset badges were added, it is a palette-only preset.
- `renderers/shared/generated-validators.mjs` — regenerated from the patched schema via
  `node scripts/generate-validators.mjs` (that script itself is not vendored; it needs the
  package's dev dependencies, which are not part of this vendored copy — pull it from an
  upstream checkout or the shared `~/.agents/skills/archify` install if you need to regenerate
  again).

## Current status

The `symfony-ai` preset is implemented and tested (`validate`/`deliver` showcase-clean), but no
spec under `docs/architecture/` currently uses it — every diagram still ships with archify's
`classic` default. Wiring it up (setting `meta.visual_preset: "symfony-ai"` on the specs) is an
open design decision, not a technical blocker.

## Re-vendoring from upstream

If archify is ever re-vendored from a newer upstream version:

1. Diff the new upstream version's `assets/template.html` against this preset's two CSS blocks
   before overwriting — the surrounding presets' structure may have moved.
2. Re-apply the `visualPreset` enum change to the new `schemas/common.schema.json`.
3. Re-run `node scripts/generate-validators.mjs` against the updated schema.
4. Re-run `node bin/archify.mjs doctor` and re-validate one spec with
   `meta.visual_preset: "symfony-ai"` to confirm the preset still renders before trusting it.
