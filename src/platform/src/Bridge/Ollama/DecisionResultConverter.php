<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Ollama;

use Symfony\AI\Platform\Bridge\TypeSafe\ResultConverter as TypeSafeResultConverter;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Converts the "POST /v1/systemone" response of Ollama into {@see \Symfony\AI\Platform\Bridge\TypeSafe\Answer\Answers}.
 *
 * The success payload is identical to the one of TypeSafe, so it is delegated to its converter; only the
 * error handling differs, as Ollama reports errors as {"error": "message"}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class DecisionResultConverter implements ResultConverterInterface
{
    use HttpStatusErrorHandlingTrait;

    private readonly TypeSafeResultConverter $converter;

    public function __construct()
    {
        $this->converter = new TypeSafeResultConverter();
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Decision;
    }

    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ObjectResult
    {
        $httpResponse = $result->getObject();

        $this->throwOnHttpError($httpResponse);

        if (200 !== $httpResponse->getStatusCode()) {
            // e.g. 413 when the request exceeds the size limit
            throw new RuntimeException(\sprintf('Unexpected response code %d: "%s"', $httpResponse->getStatusCode(), $this->extractErrorMessage($httpResponse) ?? $httpResponse->getContent(false)));
        }

        return $this->converter->convert($result, $options);
    }

    public function getTokenUsageExtractor(): TokenUsageExtractorInterface
    {
        return $this->converter->getTokenUsageExtractor();
    }

    private function extractErrorMessage(ResponseInterface $response): ?string
    {
        try {
            $data = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            return null;
        }

        $error = $data['error'] ?? null;

        return \is_string($error) ? $error : null;
    }
}
