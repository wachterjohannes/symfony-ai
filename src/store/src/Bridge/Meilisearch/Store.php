<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Meilisearch;

use Symfony\AI\Platform\Vector\NullVector;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\UnsupportedFeatureException;
use Symfony\AI\Store\Exception\UnsupportedQueryTypeException;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\Query\Filter\AndFilter;
use Symfony\AI\Store\Query\Filter\EqualFilter;
use Symfony\AI\Store\Query\Filter\FilterInterface;
use Symfony\AI\Store\Query\Filter\GreaterThanFilter;
use Symfony\AI\Store\Query\Filter\GreaterThanOrEqualFilter;
use Symfony\AI\Store\Query\Filter\InFilter;
use Symfony\AI\Store\Query\Filter\LessThanFilter;
use Symfony\AI\Store\Query\Filter\LessThanOrEqualFilter;
use Symfony\AI\Store\Query\Filter\NotEqualFilter;
use Symfony\AI\Store\Query\Filter\OrFilter;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Guillaume Loulier <personal@guillaumeloulier.fr>
 */
final class Store implements ManagedStoreInterface, StoreInterface
{
    /**
     * @param string $embedder        The name of the embedder where vectors are stored
     * @param string $vectorFieldName The name of the field in the index that contains the vector
     * @param float  $semanticRatio   The ratio between semantic (vector) and full-text search (0.0 to 1.0)
     *                                - 0.0 = 100% full-text search
     *                                - 0.5 = balanced hybrid search
     *                                - 1.0 = 100% semantic search (vector only)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $indexName,
        private readonly string $embedder = 'default',
        private readonly string $vectorFieldName = '_vectors',
        private readonly int $embeddingsDimension = 1536,
        private readonly float $semanticRatio = 1.0,
    ) {
        if ($semanticRatio < 0.0 || $semanticRatio > 1.0) {
            throw new InvalidArgumentException(\sprintf('The semantic ratio must be between 0.0 and 1.0, "%s" given.', $semanticRatio));
        }
    }

    public function setup(array $options = []): void
    {
        if ([] !== $options) {
            throw new InvalidArgumentException('No supported options.');
        }

        $this->request('POST', 'indexes', [
            'uid' => $this->indexName,
            'primaryKey' => 'id',
        ]);

        $this->request('PATCH', \sprintf('indexes/%s/settings', $this->indexName), [
            'embedders' => [
                $this->embedder => [
                    'source' => 'userProvided',
                    'dimensions' => $this->embeddingsDimension,
                ],
            ],
        ]);
    }

    public function add(VectorDocument|array $documents): void
    {
        if ($documents instanceof VectorDocument) {
            $documents = [$documents];
        }

        $this->request('PUT', \sprintf('indexes/%s/documents', $this->indexName), array_map(
            $this->convertToIndexableArray(...), $documents)
        );
    }

    public function remove(string|array $ids, array $options = []): void
    {
        if (\is_string($ids)) {
            $ids = [$ids];
        }

        if ([] === $ids) {
            return;
        }

        $this->request('POST', \sprintf('indexes/%s/documents/delete-batch', $this->indexName), $ids);
    }

    public function supports(string $queryClass): bool
    {
        return \in_array($queryClass, [
            VectorQuery::class,
            HybridQuery::class,
        ], true);
    }

    public function query(QueryInterface $query, array $options = []): iterable
    {
        if ($query instanceof HybridQuery) {
            $vector = $query->getVector();
            $text = $query->getText();
            $semanticRatio = $options['semanticRatio'] ?? $query->getSemanticRatio();
            $filter = $query->getFilter();
        } elseif ($query instanceof VectorQuery) {
            $vector = $query->getVector();
            $text = '';
            $semanticRatio = $options['semanticRatio'] ?? $this->semanticRatio;
            $filter = $query->getFilter();
        } else {
            throw new UnsupportedQueryTypeException($query::class, $this);
        }

        if ($semanticRatio < 0.0 || $semanticRatio > 1.0) {
            throw new InvalidArgumentException(\sprintf('The semantic ratio must be between 0.0 and 1.0, "%s" given.', $semanticRatio));
        }

        $payload = [
            'q' => $text,
            'vector' => $vector->getData(),
            'showRankingScore' => true,
            'retrieveVectors' => true,
            'hybrid' => [
                'embedder' => $this->embedder,
                'semanticRatio' => $semanticRatio,
            ],
        ];

        if (null !== $filter) {
            $payload['filter'] = $this->convertFilter($filter);
        }

        $result = $this->request('POST', \sprintf('indexes/%s/search', $this->indexName), $payload);

        foreach ($result['hits'] as $item) {
            yield $this->convertToVectorDocument($item);
        }
    }

    public function drop(array $options = []): void
    {
        $this->request('DELETE', \sprintf('indexes/%s', $this->indexName), []);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $payload): array
    {
        $result = $this->httpClient->request($method, $endpoint, [
            'json' => $payload,
        ]);

        return $result->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function convertToIndexableArray(VectorDocument $document): array
    {
        return array_merge([
            'id' => $document->getId(),
            $this->vectorFieldName => [
                $this->embedder => [
                    'embeddings' => $document->getVector()->getData(),
                    'regenerate' => false,
                ],
            ],
        ], $document->getMetadata()->getArrayCopy());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function convertToVectorDocument(array $data): VectorDocument
    {
        $id = $data['id'] ?? throw new InvalidArgumentException('Missing "id" field in the document data.');
        $vector = !\array_key_exists($this->vectorFieldName, $data) || null === $data[$this->vectorFieldName]
            ? new NullVector()
            : new Vector($data[$this->vectorFieldName][$this->embedder]['embeddings']);

        $score = $data['_rankingScore'] ?? null;

        unset($data['id'], $data[$this->vectorFieldName], $data['_rankingScore']);

        return new VectorDocument($id, $vector, new Metadata($data), $score);
    }

    /**
     * Converts a query-level filter into a Meilisearch filter expression.
     *
     * The filtered attributes must be declared as `filterableAttributes` in
     * the index settings.
     *
     * @see https://www.meilisearch.com/docs/learn/filtering_and_sorting/filter_expression_reference
     */
    private function convertFilter(FilterInterface $filter): string
    {
        return match (true) {
            $filter instanceof AndFilter => '('.implode(' AND ', array_map($this->convertFilter(...), $filter->getFilters())).')',
            $filter instanceof OrFilter => '('.implode(' OR ', array_map($this->convertFilter(...), $filter->getFilters())).')',
            $filter instanceof EqualFilter => \sprintf('%s = %s', $this->convertField($filter->getField()), $this->convertValue($filter->getValue())),
            $filter instanceof NotEqualFilter => \sprintf('%s != %s', $this->convertField($filter->getField()), $this->convertValue($filter->getValue())),
            $filter instanceof InFilter => \sprintf('%s IN [%s]', $this->convertField($filter->getField()), implode(', ', array_map($this->convertValue(...), $filter->getValues()))),
            $filter instanceof GreaterThanFilter => \sprintf('%s > %s', $this->convertField($filter->getField()), $this->convertRangeValue($filter->getValue())),
            $filter instanceof GreaterThanOrEqualFilter => \sprintf('%s >= %s', $this->convertField($filter->getField()), $this->convertRangeValue($filter->getValue())),
            $filter instanceof LessThanFilter => \sprintf('%s < %s', $this->convertField($filter->getField()), $this->convertRangeValue($filter->getValue())),
            $filter instanceof LessThanOrEqualFilter => \sprintf('%s <= %s', $this->convertField($filter->getField()), $this->convertRangeValue($filter->getValue())),
            default => throw new UnsupportedFeatureException(\sprintf('Filter type "%s" is not supported by store "%s".', $filter::class, self::class)),
        };
    }

    private function convertField(string $field): string
    {
        if (1 !== preg_match('/^[a-zA-Z0-9_.\\-]+$/', $field)) {
            throw new InvalidArgumentException(\sprintf('Invalid filter field name "%s".', $field));
        }

        return $field;
    }

    private function convertValue(string|int|float|bool $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_string($value)) {
            return '"'.addcslashes($value, '\\"').'"';
        }

        return (string) $value;
    }

    private function convertRangeValue(string|int|float $value): string
    {
        if (\is_string($value)) {
            throw new UnsupportedFeatureException(\sprintf('Store "%s" only supports numeric values in range filters, "string" given.', self::class));
        }

        return (string) $value;
    }
}
