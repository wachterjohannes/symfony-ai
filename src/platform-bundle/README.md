# Symfony AI Platform Bundle (spike)

Core bundle of a per-component / per-bridge split of `symfony/ai-bundle`. It works **without
`symfony/framework-bundle`**: only `config`, `dependency-injection` and `http-kernel` are required.

- `PlatformBundle` (alias `ai_platform`) knows no bridge. It collects every service tagged
  `ai.platform` (attribute `name`) into the `ai_platform.platforms` service locator, registers the
  `PlatformInterface $<name>` autowiring aliases and the `ai:platform:invoke` command.
- Bridge bundles (e.g. `Symfony\AI\Platform\Bridge\OpenAi\OpenAiBundle`, alias `ai_openai`) extend
  `AbstractPlatformBridgeBundle`, own their config tree and call `registerPlatform()`.
- `DefaultServicesPass` defines `http_client` / `event_dispatcher` only when no one else did,
  so framework apps keep their own services.

See `examples/webless` for a console-only app.
