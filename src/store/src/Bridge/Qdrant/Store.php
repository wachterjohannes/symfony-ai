<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Qdrant;

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
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Guillaume Loulier <personal@guillaumeloulier.fr>
 */
final class Store implements ManagedStoreInterface, StoreInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $collectionName,
        private readonly int $embeddingsDimension = 1536,
        private readonly string $embeddingsDistance = 'Cosine',
        private readonly bool $async = false,
    ) {
    }

    public function setup(array $options = []): void
    {
        if ([] !== $options) {
            throw new InvalidArgumentException('No supported options.');
        }

        $collectionExistResponse = $this->request('GET', \sprintf('collections/%s/exists', $this->collectionName));

        if ($collectionExistResponse['result']['exists']) {
            return;
        }

        $this->request('PUT', \sprintf('collections/%s', $this->collectionName), [
            'vectors' => [
                'size' => $this->embeddingsDimension,
                'distance' => $this->embeddingsDistance,
            ],
        ]);
    }

    public function add(VectorDocument|array $documents): void
    {
        if ($documents instanceof VectorDocument) {
            $documents = [$documents];
        }

        $this->request(
            'PUT',
            \sprintf('collections/%s/points', $this->collectionName),
            [
                'points' => array_map(static fn (VectorDocument $document): array => [
                    'id' => $document->getId(),
                    'vector' => $document->getVector()->getData(),
                    'payload' => $document->getMetadata()->getArrayCopy(),
                ], $documents),
            ],
            ['wait' => $this->async ? 'false' : 'true'],
        );
    }

    public function remove(string|array $ids, array $options = []): void
    {
        if (\is_string($ids)) {
            $ids = [$ids];
        }

        $this->request(
            'POST',
            \sprintf('collections/%s/points/delete', $this->collectionName),
            [
                'points' => $ids,
            ],
            ['wait' => $this->async ? 'false' : 'true'],
        );
    }

    public function supports(string $queryClass): bool
    {
        return VectorQuery::class === $queryClass;
    }

    /**
     * @param array{
     *     filter?: array<string, mixed>,
     *     limit?: positive-int,
     *     offset?: positive-int
     * } $options
     */
    public function query(QueryInterface $query, array $options = []): iterable
    {
        if (!$query instanceof VectorQuery) {
            throw new UnsupportedQueryTypeException($query::class, $this);
        }

        $payload = [
            'query' => $query->getVector()->getData(),
            'with_payload' => true,
            'with_vector' => true,
        ];

        if (null !== $query->getFilter()) {
            if (isset($options['filter'])) {
                throw new InvalidArgumentException('The "filter" option cannot be combined with a query-level filter.');
            }

            $payload['filter'] = $this->convertFilter($query->getFilter());
        } elseif (isset($options['filter'])) {
            $payload['filter'] = $options['filter'];
        }

        if (\array_key_exists('limit', $options)) {
            $payload['limit'] = $options['limit'];
        }

        if (\array_key_exists('offset', $options)) {
            $payload['offset'] = $options['offset'];
        }

        $response = $this->request('POST', \sprintf('collections/%s/points/query', $this->collectionName), $payload);

        foreach ($response['result']['points'] as $item) {
            yield $this->convertToVectorDocument($item);
        }
    }

    public function drop(array $options = []): void
    {
        $this->request('DELETE', \sprintf('collections/%s', $this->collectionName));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $queryParameters
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $payload = [], array $queryParameters = []): array
    {
        $response = $this->httpClient->request($method, $endpoint, [
            'query' => $queryParameters,
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function convertToVectorDocument(array $data): VectorDocument
    {
        $id = $data['id'] ?? throw new InvalidArgumentException('Missing "id" field in the document data.');

        $vector = !\array_key_exists('vector', $data) || null === $data['vector']
            ? new NullVector()
            : new Vector($data['vector']);

        return new VectorDocument(
            id: $id,
            vector: $vector,
            metadata: new Metadata($data['payload']),
            score: $data['score'] ?? null
        );
    }

    /**
     * Converts a query-level filter into a Qdrant filter object.
     *
     * @return array<string, mixed>
     *
     * @see https://qdrant.tech/documentation/concepts/filtering/
     */
    private function convertFilter(FilterInterface $filter): array
    {
        $condition = $this->convertCondition($filter);

        // field conditions have to be wrapped in a filter clause at the top level
        if (\array_key_exists('key', $condition)) {
            return ['must' => [$condition]];
        }

        return $condition;
    }

    /**
     * @return array<string, mixed>
     */
    private function convertCondition(FilterInterface $filter): array
    {
        return match (true) {
            $filter instanceof AndFilter => ['must' => array_map($this->convertCondition(...), $filter->getFilters())],
            $filter instanceof OrFilter => ['should' => array_map($this->convertCondition(...), $filter->getFilters())],
            $filter instanceof EqualFilter => ['key' => $filter->getField(), 'match' => ['value' => $this->convertMatchValue($filter->getValue())]],
            $filter instanceof NotEqualFilter => ['must_not' => [['key' => $filter->getField(), 'match' => ['value' => $this->convertMatchValue($filter->getValue())]]]],
            $filter instanceof InFilter => ['key' => $filter->getField(), 'match' => ['any' => $this->convertInValues($filter)]],
            $filter instanceof GreaterThanFilter => ['key' => $filter->getField(), 'range' => ['gt' => $this->convertRangeValue($filter->getValue())]],
            $filter instanceof GreaterThanOrEqualFilter => ['key' => $filter->getField(), 'range' => ['gte' => $this->convertRangeValue($filter->getValue())]],
            $filter instanceof LessThanFilter => ['key' => $filter->getField(), 'range' => ['lt' => $this->convertRangeValue($filter->getValue())]],
            $filter instanceof LessThanOrEqualFilter => ['key' => $filter->getField(), 'range' => ['lte' => $this->convertRangeValue($filter->getValue())]],
            default => throw new UnsupportedFeatureException(\sprintf('Filter type "%s" is not supported by store "%s".', $filter::class, self::class)),
        };
    }

    /**
     * Qdrant match conditions only accept keyword (string), integer or boolean
     * values, floats can only be expressed through range conditions.
     *
     * @see https://qdrant.tech/documentation/concepts/filtering/#match
     */
    private function convertMatchValue(string|int|float|bool $value): string|int|bool
    {
        if (\is_float($value)) {
            throw new UnsupportedFeatureException(\sprintf('Store "%s" does not support float values in match conditions, use range filters instead.', self::class));
        }

        return $value;
    }

    /**
     * @return list<string>|list<int|float>
     */
    private function convertInValues(InFilter $filter): array
    {
        foreach ($filter->getValues() as $value) {
            if (\is_bool($value)) {
                throw new UnsupportedFeatureException(\sprintf('Store "%s" does not support boolean values in "%s", use "%s" instead.', self::class, InFilter::class, EqualFilter::class));
            }

            if (\is_float($value)) {
                throw new UnsupportedFeatureException(\sprintf('Store "%s" does not support float values in "%s", use range filters instead.', self::class, InFilter::class));
            }
        }

        return $filter->getValues();
    }

    private function convertRangeValue(string|int|float $value): int|float
    {
        if (\is_string($value)) {
            throw new UnsupportedFeatureException(\sprintf('Store "%s" only supports numeric values in range filters, "string" given.', self::class));
        }

        return $value;
    }
}
