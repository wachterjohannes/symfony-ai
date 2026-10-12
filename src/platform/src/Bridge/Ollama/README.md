Ollama Platform
===============

Ollama platform bridge for Symfony AI.

Decision models
---------------

Models declaring the `decision` capability (Ollama >= 0.35.0) are served through `POST /v1/systemone`.
They are queried with the `Evaluation` of the Platform's `Decision` namespace and answer with its `Answers`::

    use Symfony\AI\Platform\Bridge\Ollama\Factory;
    use Symfony\AI\Platform\Decision\Evaluation;
    use Symfony\AI\Platform\Decision\Question\ChoiceQuestion;

    $platform = Factory::createPlatform('http://localhost:11434');
    $answers = $platform->invoke('nimble', new Evaluation('Our checkout has returned 500 errors since 9am.', [
        'label' => new ChoiceQuestion('Which label fits this ticket?', ['billing' => 'Payments', 'bug' => 'Software errors']),
    ]))->asObject();

    $answers->getChoice('label')->getChoice(); // "bug"

Images (Clef models) are not supported yet.

Ollama Documentation
--------------------

 * [API reference (chat, embed, NDJSON streaming)](https://github.com/ollama/ollama/blob/main/docs/api.md)
 * [Modelfile / capabilities](https://github.com/ollama/ollama/blob/main/docs/modelfile.mdx)

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/ai/issues) and
   [send Pull Requests](https://github.com/symfony/ai/pulls)
   in the [main Symfony AI repository](https://github.com/symfony/ai)
