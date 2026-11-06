<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Toolbox\ToolSearch;

use Symfony\AI\Platform\Tool\Tool;

/**
 * Ranks tools with the Okapi BM25 scoring function, without any external dependency.
 *
 * The searchable document of a tool consists of its name, its description and the names,
 * descriptions and enum values of its parameters.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class Bm25ToolSearch implements ToolSearchInterface
{
    /**
     * @param float $termFrequencySaturation the BM25 "k1" parameter, controlling how fast the relevance of a term saturates with its frequency
     * @param float $lengthNormalization     the BM25 "b" parameter, controlling how strong long documents are penalized
     */
    public function __construct(
        private readonly float $termFrequencySaturation = 1.2,
        private readonly float $lengthNormalization = 0.75,
    ) {
    }

    public function search(string $query, array $tools, int $maxResults): array
    {
        $tools = array_values($tools);

        if ([] === $tools || $maxResults < 1) {
            return [];
        }

        $queryTerms = array_unique($this->tokenize($query));
        if ([] === $queryTerms) {
            return [];
        }

        $frequencies = [];
        $lengths = [];
        foreach ($tools as $index => $tool) {
            $terms = $this->tokenize($this->toDocument($tool));
            $frequencies[$index] = array_count_values($terms);
            $lengths[$index] = \count($terms);
        }

        $averageLength = array_sum($lengths) / \count($lengths);
        if ($averageLength <= 0.0) {
            return [];
        }

        $documentFrequencies = [];
        foreach ($queryTerms as $term) {
            $documentFrequencies[$term] = \count(array_filter($frequencies, static fn (array $frequency): bool => isset($frequency[$term])));
        }

        $scores = [];
        foreach ($frequencies as $index => $frequency) {
            $score = 0.0;
            foreach ($documentFrequencies as $term => $documentFrequency) {
                if (0 === $documentFrequency || !isset($frequency[$term])) {
                    continue;
                }

                $inverseDocumentFrequency = log(1 + (\count($tools) - $documentFrequency + 0.5) / ($documentFrequency + 0.5));
                $termFrequency = $frequency[$term];

                $score += $inverseDocumentFrequency * ($termFrequency * ($this->termFrequencySaturation + 1))
                    / ($termFrequency + $this->termFrequencySaturation * (1 - $this->lengthNormalization + $this->lengthNormalization * $lengths[$index] / $averageLength));
            }

            if ($score > 0.0) {
                $scores[$index] = $score;
            }
        }

        arsort($scores);

        return array_map(static fn (int $index): Tool => $tools[$index], \array_slice(array_keys($scores), 0, $maxResults));
    }

    private function toDocument(Tool $tool): string
    {
        $parameters = $tool->getParameters();

        return implode(' ', [
            $tool->getName(),
            $tool->getDescription(),
            null === $parameters ? '' : $this->flatten($parameters),
        ]);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function flatten(array $schema): string
    {
        $parts = [];
        foreach ($schema as $key => $value) {
            if ('properties' === $key && \is_array($value)) {
                foreach ($value as $name => $property) {
                    $parts[] = (string) $name;
                    if (\is_array($property)) {
                        $parts[] = $this->flatten($property);
                    }
                }

                continue;
            }

            if ('description' === $key && \is_string($value)) {
                $parts[] = $value;

                continue;
            }

            if ('enum' === $key && \is_array($value)) {
                foreach ($value as $option) {
                    if (\is_string($option)) {
                        $parts[] = $option;
                    }
                }

                continue;
            }

            if (\is_array($value)) {
                $parts[] = $this->flatten($value);
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = strtolower((string) preg_replace('/([a-z\d])([A-Z])/', '$1 $2', $text));
        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $tokens) {
            return [];
        }

        return array_map($this->normalize(...), $tokens);
    }

    /**
     * Very light normalization of plurals, so that "emails" matches a tool describing an "email".
     */
    private function normalize(string $token): string
    {
        if (\strlen($token) > 4 && str_ends_with($token, 'ies')) {
            return substr($token, 0, -3).'y';
        }

        if (\strlen($token) > 3 && str_ends_with($token, 's') && !str_ends_with($token, 'ss')) {
            return substr($token, 0, -1);
        }

        return $token;
    }
}
