# Webless demo

Console-only app using `symfony/ai-platform` and the OpenAI bridge **without `symfony/framework-bundle`**.
It boots a plain `HttpKernel` with `Symfony\AI\Platform\Bundle\PlatformBundle` and
`Symfony\AI\Platform\Bridge\OpenAi\OpenAiBundle`.

```bash
composer install
OPENAI_API_KEY=sk-... php bin/console ai:platform:invoke "Say hello"
# no key: answer from a MockHttpClient
DEMO_MOCK_HTTP=1 OPENAI_API_KEY=sk-mock php bin/console ai:platform:invoke "Say hello"
composer why symfony/framework-bundle   # not in the dependency set
```
