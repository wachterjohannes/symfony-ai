<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Qdrant\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Qdrant\Store;
use Symfony\AI\Store\Bridge\Qdrant\StoreFactory;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\UnsupportedFeatureException;
use Symfony\AI\Store\Query\Filter\AndFilter;
use Symfony\AI\Store\Query\Filter\EqualFilter;
use Symfony\AI\Store\Query\Filter\GreaterThanFilter;
use Symfony\AI\Store\Query\Filter\InFilter;
use Symfony\AI\Store\Query\Filter\LessThanOrEqualFilter;
use Symfony\AI\Store\Query\Filter\NotEqualFilter;
use Symfony\AI\Store\Query\Filter\OrFilter;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Uid\Uuid;

final class StoreTest extends TestCase
{
    public function testStoreCannotSetupOnInvalidResponse()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'status' => 'ok',
                'result' => [
                    'exists' => false,
                ],
            ], [
                'http_code' => 200,
            ]),
            new JsonMockResponse([], [
                'http_code' => 400,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('HTTP 400 returned for "http://127.0.0.1:6333/collections/test".');
        $this->expectExceptionCode(400);
        $store->setup();
    }

    public function testStoreCanSetupOnExistingCollection()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'status' => 'ok',
                'result' => [
                    'exists' => true,
                ],
            ], [
                'http_code' => 200,
            ]),
            new JsonMockResponse([
                'status' => 'ok',
                'result' => true,
            ], [
                'http_code' => 200,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $store->setup();

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testStoreCannotDropOnInvalidResponse()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([], [
                'http_code' => 400,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('HTTP 400 returned for "http://127.0.0.1:6333/collections/test".');
        $this->expectExceptionCode(400);
        $store->drop();
    }

    public function testStoreCanDropOnExistingCollection()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'status' => 'ok',
                'result' => [
                    'exists' => true,
                ],
            ], [
                'http_code' => 200,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $store->drop();

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testStoreCannotSetup()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'status' => 'ok',
                'result' => [
                    'exists' => false,
                ],
            ], [
                'http_code' => 200,
            ]),
            new JsonMockResponse([
                'status' => 'ok',
                'result' => true,
            ], [
                'http_code' => 200,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $store->setup();

        $this->assertSame(2, $httpClient->getRequestsCount());
    }

    public function testStoreCannotAddOnInvalidResponse()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([], [
                'http_code' => 400,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('HTTP 400 returned for "http://127.0.0.1:6333/collections/test/points?wait=true".');
        $this->expectExceptionCode(400);
        $store->add([new VectorDocument(Uuid::v4(), new Vector([0.1, 0.2, 0.3]))]);
    }

    public function testStoreCanAdd()
    {
        $document = new VectorDocument(Uuid::v4(), new Vector([0.1, 0.2, 0.3]));

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($document): JsonMockResponse {
            self::assertArrayHasKey('wait', $options['query']);
            self::assertSame('true', $options['query']['wait']);

            return new JsonMockResponse([
                'time' => 0.002,
                'status' => 'ok',
                'result' => [
                    'points' => [
                        [
                            'id' => (string) $document->getId(),
                            'payload' => (array) $document->getMetadata(),
                            'vector' => $document->getVector()->getData(),
                        ],
                    ],
                ],
            ], [
                'http_code' => 200,
            ]);
        }, 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $store->add($document);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testStoreCanAddAsynchronously()
    {
        $document = new VectorDocument(Uuid::v4(), new Vector([0.1, 0.2, 0.3]));

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): JsonMockResponse {
            self::assertArrayHasKey('wait', $options['query']);
            self::assertSame('false', $options['query']['wait']);

            return new JsonMockResponse([
                'time' => 0.002,
                'status' => 'ok',
                'result' => [
                    'status' => 'acknowledged',
                    'operation_id' => 1000000,
                ],
            ], [
                'http_code' => 200,
            ]);
        }, 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test', async: true);

        $store->add($document);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testStoreCannotQueryOnInvalidResponse()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([], [
                'http_code' => 400,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('HTTP 400 returned for "http://127.0.0.1:6333/collections/test/points/query".');
        $this->expectExceptionCode(400);
        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]))));
    }

    public function testStoreCanQuery()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'result' => [
                    'points' => [
                        [
                            'id' => Uuid::v4()->toRfc4122(),
                            'vector' => [0.1, 0.2, 0.3],
                            'payload' => [],
                        ],
                        [
                            'id' => Uuid::v4()->toRfc4122(),
                            'vector' => [0.2, 0.1, 0.3],
                            'payload' => [],
                        ],
                    ],
                ],
            ], [
                'http_code' => 200,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $results = iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]))));

        $this->assertSame(1, $httpClient->getRequestsCount());
        $this->assertCount(2, $results);
    }

    public function testStoreCanQueryWithFilters()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'result' => [
                    'points' => [
                        [
                            'id' => Uuid::v4()->toRfc4122(),
                            'vector' => [0.1, 0.2, 0.3],
                            'payload' => ['foo' => 'bar'],
                        ],
                        [
                            'id' => Uuid::v4()->toRfc4122(),
                            'vector' => [0.2, 0.1, 0.3],
                            'payload' => ['foo' => ['bar', 'baz']],
                        ],
                    ],
                ],
            ], [
                'http_code' => 200,
            ]),
        ], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $results = iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3])), [
            'filter' => [
                'must' => [
                    ['key' => 'foo', 'match' => ['value' => 'bar']],
                ],
            ],
        ]));

        $this->assertSame(1, $httpClient->getRequestsCount());
        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertArrayHasKey('foo', $result->getMetadata());
            $this->assertTrue(
                'bar' === $result->getMetadata()['foo'] || (\is_array($result->getMetadata()['foo']) && \in_array('bar', $result->getMetadata()['foo'], true)),
                "Value should be 'bar' or an array containing 'bar'"
            );
        }
    }

    public function testStoreCanQueryWithEqualQueryFilter()
    {
        $response = new JsonMockResponse([
            'result' => [
                'points' => [],
            ],
        ], [
            'http_code' => 200,
        ]);
        $httpClient = new MockHttpClient([$response], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new EqualFilter('locale', 'en'))));

        $body = json_decode($response->getRequestOptions()['body'], true);

        $this->assertSame([
            'must' => [
                ['key' => 'locale', 'match' => ['value' => 'en']],
            ],
        ], $body['filter']);
    }

    public function testStoreCanQueryWithInQueryFilter()
    {
        $response = new JsonMockResponse([
            'result' => [
                'points' => [],
            ],
        ], [
            'http_code' => 200,
        ]);
        $httpClient = new MockHttpClient([$response], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new InFilter('category', ['scifi', 'fantasy']))));

        $body = json_decode($response->getRequestOptions()['body'], true);

        $this->assertSame([
            'must' => [
                ['key' => 'category', 'match' => ['any' => ['scifi', 'fantasy']]],
            ],
        ], $body['filter']);
    }

    public function testStoreCanQueryWithCompositeQueryFilter()
    {
        $response = new JsonMockResponse([
            'result' => [
                'points' => [],
            ],
        ], [
            'http_code' => 200,
        ]);
        $httpClient = new MockHttpClient([$response], 'http://127.0.0.1:6333');

        $store = new Store($httpClient, 'test');

        $filter = new AndFilter([
            new EqualFilter('locale', 'en'),
            new OrFilter([
                new NotEqualFilter('category', 'crime'),
                new GreaterThanFilter('year', 2020),
            ]),
            new LessThanOrEqualFilter('price', 99.9),
        ]);

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), $filter)));

        $body = json_decode($response->getRequestOptions()['body'], true);

        $this->assertSame([
            'must' => [
                ['key' => 'locale', 'match' => ['value' => 'en']],
                [
                    'should' => [
                        ['must_not' => [['key' => 'category', 'match' => ['value' => 'crime']]]],
                        ['key' => 'year', 'range' => ['gt' => 2020]],
                    ],
                ],
                ['key' => 'price', 'range' => ['lte' => 99.9]],
            ],
        ], $body['filter']);
    }

    public function testStoreCannotCombineQueryFilterWithFilterOption()
    {
        $httpClient = new MockHttpClient([], 'http://127.0.0.1:6333');
        $store = new Store($httpClient, 'test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "filter" option cannot be combined with a query-level filter.');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new EqualFilter('locale', 'en')), [
            'filter' => ['must' => [['key' => 'locale', 'match' => ['value' => 'en']]]],
        ]));
    }

    public function testStoreCannotQueryWithStringRangeQueryFilter()
    {
        $httpClient = new MockHttpClient([], 'http://127.0.0.1:6333');
        $store = new Store($httpClient, 'test');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('only supports numeric values in range filters');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new GreaterThanFilter('title', 'alpha'))));
    }

    public function testStoreCannotQueryWithFloatEqualQueryFilter()
    {
        $httpClient = new MockHttpClient([], 'http://127.0.0.1:6333');
        $store = new Store($httpClient, 'test');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support float values in match conditions');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new EqualFilter('price', 9.99))));
    }

    public function testStoreCannotQueryWithFloatNotEqualQueryFilter()
    {
        $httpClient = new MockHttpClient([], 'http://127.0.0.1:6333');
        $store = new Store($httpClient, 'test');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support float values in match conditions');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new NotEqualFilter('price', 9.99))));
    }

    public function testStoreCannotQueryWithFloatInQueryFilter()
    {
        $httpClient = new MockHttpClient([], 'http://127.0.0.1:6333');
        $store = new Store($httpClient, 'test');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support float values');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new InFilter('price', [9.99, 19.99]))));
    }

    public function testStoreCannotQueryWithBooleanInQueryFilter()
    {
        $httpClient = new MockHttpClient([], 'http://127.0.0.1:6333');
        $store = new Store($httpClient, 'test');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support boolean values');

        iterator_to_array($store->query(new VectorQuery(new Vector([0.1, 0.2, 0.3]), new InFilter('enabled', [true, false]))));
    }

    public function testStoreSupportsVectorQuery()
    {
        $store = StoreFactory::create('test', 'http://127.0.0.1:6333', 'test', new MockHttpClient());
        $this->assertTrue($store->supports(VectorQuery::class));
    }

    public function testStoreDoesNotSupportTextQuery()
    {
        $store = StoreFactory::create('test', 'http://127.0.0.1:6333', 'test', new MockHttpClient());
        $this->assertFalse($store->supports(TextQuery::class));
    }

    public function testStoreDoesNotSupportHybridQuery()
    {
        $store = StoreFactory::create('test', 'http://127.0.0.1:6333', 'test', new MockHttpClient());
        $this->assertFalse($store->supports(HybridQuery::class));
    }
}
