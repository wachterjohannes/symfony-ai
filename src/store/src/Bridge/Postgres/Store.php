<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Postgres;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Platform\Vector\VectorInterface;
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
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;

/**
 * Requires PostgreSQL with pgvector extension.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @see https://github.com/pgvector/pgvector
 */
final class Store implements ManagedStoreInterface, StoreInterface
{
    public function __construct(
        private readonly \PDO $connection,
        private readonly string $tableName,
        private readonly string $vectorFieldName = 'embedding',
        private readonly Distance $distance = Distance::L2,
        private readonly string $lang = 'english',
    ) {
    }

    /**
     * @param array{vector_type?: string, vector_size?: positive-int, index_method?: string, index_opclass?: string} $options
     *
     * Good configuration $options are:
     * - For Mistral: ['vector_size' => 1024]
     * - For Gemini: ['vector_type' => 'halfvec', 'vector_size' => 3072, 'index_method' => 'hnsw', 'index_opclass' => 'halfvec_cosine_ops']
     */
    public function setup(array $options = []): void
    {
        $this->connection->exec('CREATE EXTENSION IF NOT EXISTS vector');

        $this->connection->exec(
            \sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    id UUID PRIMARY KEY,
                    metadata JSONB,
                    %s %s(%d) NOT NULL
                )',
                $this->tableName,
                $this->vectorFieldName,
                $options['vector_type'] ?? 'vector',
                $options['vector_size'] ?? 1536,
            ),
        );
        $this->connection->exec(
            \sprintf(
                'CREATE INDEX IF NOT EXISTS %s_%s_idx ON %s USING %s (%s %s)',
                $this->tableName,
                $this->vectorFieldName,
                $this->tableName,
                $options['index_method'] ?? 'ivfflat',
                $this->vectorFieldName,
                $options['index_opclass'] ?? 'vector_cosine_ops',
            ),
        );
    }

    public function drop(array $options = []): void
    {
        $this->connection->exec(\sprintf('DROP TABLE IF EXISTS %s', $this->tableName));
    }

    public function add(VectorDocument|array $documents): void
    {
        if ($documents instanceof VectorDocument) {
            $documents = [$documents];
        }

        $statement = $this->connection->prepare(
            \sprintf(
                'INSERT INTO %1$s (id, metadata, %2$s)
                VALUES (:id, :metadata, :vector)
                ON CONFLICT (id) DO UPDATE SET metadata = EXCLUDED.metadata, %2$s = EXCLUDED.%2$s',
                $this->tableName,
                $this->vectorFieldName,
            ),
        );

        foreach ($documents as $document) {
            $statement->bindValue(':id', $document->getId());
            $statement->bindValue(':metadata', json_encode($document->getMetadata()->getArrayCopy(), \JSON_THROW_ON_ERROR));
            $statement->bindValue(':vector', $this->toPgvector($document->getVector()));

            $statement->execute();
        }
    }

    public function remove(string|array $ids, array $options = []): void
    {
        if (\is_string($ids)) {
            $ids = [$ids];
        }

        if ([] === $ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $sql = \sprintf('DELETE FROM %s WHERE id IN (%s)', $this->tableName, $placeholders);

        $statement = $this->connection->prepare($sql);

        foreach ($ids as $index => $id) {
            $statement->bindValue($index + 1, $id);
        }

        $statement->execute();
    }

    public function supports(string $queryClass): bool
    {
        return \in_array($queryClass, [
            VectorQuery::class,
            TextQuery::class,
            HybridQuery::class,
        ], true);
    }

    /**
     * @param array{limit?: positive-int, maxScore?: float} $options
     */
    public function query(QueryInterface $query, array $options = []): iterable
    {
        return match (true) {
            $query instanceof VectorQuery => $this->queryVector($query, $options),
            $query instanceof TextQuery => $this->queryText($query, $options),
            $query instanceof HybridQuery => $this->queryHybrid($query, $options),
            default => throw new UnsupportedQueryTypeException($query::class, $this),
        };
    }

    /**
     * @param array{limit?: positive-int, maxScore?: float, where?: string, params?: array<string, mixed>} $options
     *
     * @return iterable<VectorDocument>
     */
    private function queryVector(VectorQuery $query, array $options): iterable
    {
        $whereClauses = [];
        $params = ['embedding' => $this->toPgvector($query->getVector())];

        if (isset($options['maxScore'])) {
            $whereClauses[] = \sprintf('(%s %s :embedding) <= :maxScore', $this->vectorFieldName, $this->distance->getComparisonSign());
            $params['maxScore'] = $options['maxScore'];
        }

        if (null !== $query->getFilter()) {
            $filterParams = [];
            $whereClauses[] = $this->convertFilter($query->getFilter(), $filterParams);
            $params = array_merge($params, $filterParams);
        }

        if (isset($options['where'])) {
            // Only wrap in parentheses if combining with other conditions
            $whereClauses[] = [] !== $whereClauses ? '('.$options['where'].')' : $options['where'];
        }

        if (isset($options['params'])) {
            $params = array_merge($params, $options['params']);
        }

        $where = [] !== $whereClauses ? 'WHERE '.implode(' AND ', $whereClauses) : '';

        $sql = \sprintf(<<<SQL
            SELECT id, %s AS embedding, metadata, (%s %s :embedding) AS score
            FROM %s
            %s
            ORDER BY score ASC
            LIMIT %d
            SQL,
            $this->vectorFieldName,
            $this->vectorFieldName,
            $this->distance->getComparisonSign(),
            $this->tableName,
            $where,
            $options['limit'] ?? 5,
        );

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue(':'.$key, $value);
        }

        $statement->execute();

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $result) {
            yield new VectorDocument(
                id: $result['id'],
                vector: new Vector($this->fromPgvector($result['embedding'])),
                metadata: new Metadata(json_decode($result['metadata'] ?? '{}', true, 512, \JSON_THROW_ON_ERROR)),
                score: $result['score'],
            );
        }
    }

    /**
     * @param array{limit?: positive-int} $options
     *
     * @return iterable<VectorDocument>
     */
    private function queryText(TextQuery $query, array $options): iterable
    {
        $texts = $query->getTexts();
        $tsqueryParts = [];
        $params = [];

        // Build OR-combined tsquery for multiple texts
        foreach ($texts as $i => $text) {
            $paramName = 'search_text_'.$i;
            $tsqueryParts[] = \sprintf("plainto_tsquery('%s', :%s)", $this->lang, $paramName);
            $params[$paramName] = $text;
        }

        $tsqueryExpression = implode(' || ', $tsqueryParts); // OR operator in PostgreSQL
        $tsvectorExpression = \sprintf("to_tsvector('%s', metadata->>'_text')", $this->lang);

        $filterClause = '';

        if (null !== $query->getFilter()) {
            $filterParams = [];
            $filterClause = ' AND '.$this->convertFilter($query->getFilter(), $filterParams);
            $params = array_merge($params, $filterParams);
        }

        $sql = \sprintf(<<<SQL
            SELECT id, %s AS embedding, metadata,
                   ts_rank(%s, %s) AS score
            FROM %s
            WHERE %s @@ (%s)%s
            ORDER BY score DESC
            LIMIT %d
            SQL,
            $this->vectorFieldName,
            $tsvectorExpression,
            $tsqueryExpression,
            $this->tableName,
            $tsvectorExpression,
            $tsqueryExpression,
            $filterClause,
            $options['limit'] ?? 5,
        );

        $statement = $this->connection->prepare($sql);
        foreach ($params as $paramName => $value) {
            $statement->bindValue(':'.$paramName, $value);
        }

        $statement->execute();

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $result) {
            yield new VectorDocument(
                id: $result['id'],
                vector: new Vector($this->fromPgvector($result['embedding'])),
                metadata: new Metadata(json_decode($result['metadata'] ?? '{}', true, 512, \JSON_THROW_ON_ERROR)),
                score: $result['score'],
            );
        }
    }

    /**
     * @param array{limit?: positive-int, maxScore?: float} $options
     *
     * @return iterable<VectorDocument>
     */
    private function queryHybrid(HybridQuery $query, array $options): iterable
    {
        $texts = $query->getTexts();
        $tsqueryParts = [];
        $params = [
            'embedding' => $this->toPgvector($query->getVector()),
            'semantic_ratio' => $query->getSemanticRatio(),
            'keyword_ratio' => $query->getKeywordRatio(),
        ];

        // Build OR-combined tsquery for multiple texts
        foreach ($texts as $i => $text) {
            $paramName = 'search_text_'.$i;
            $tsqueryParts[] = \sprintf("plainto_tsquery('%s', :%s)", $this->lang, $paramName);
            $params[$paramName] = $text;
        }

        $tsqueryExpression = '('.implode(' || ', $tsqueryParts).')'; // OR operator in PostgreSQL
        $tsvectorExpression = \sprintf("to_tsvector('%s', metadata->>'_text')", $this->lang);

        $where = \sprintf('WHERE %s @@ %s', $tsvectorExpression, $tsqueryExpression);

        if (null !== $query->getFilter()) {
            $filterParams = [];
            $where .= ' AND '.$this->convertFilter($query->getFilter(), $filterParams);
            $params = array_merge($params, $filterParams);
        }

        if (isset($options['maxScore'])) {
            $where .= \sprintf(' AND ((:semantic_ratio * (1 - (%s %s :embedding))) + (:keyword_ratio * ts_rank(%s, %s))) >= :maxScore',
                $this->vectorFieldName,
                $this->distance->getComparisonSign(),
                $tsvectorExpression,
                $tsqueryExpression
            );
            $params['maxScore'] = $options['maxScore'];
        }

        $sql = \sprintf(<<<SQL
            SELECT id, %s AS embedding, metadata,
                   ((:semantic_ratio * (1 - (%s %s :embedding))) +
                    (:keyword_ratio * ts_rank(%s, %s))) AS score
            FROM %s
            %s
            ORDER BY score DESC
            LIMIT %d
            SQL,
            $this->vectorFieldName,
            $this->vectorFieldName,
            $this->distance->getComparisonSign(),
            $tsvectorExpression,
            $tsqueryExpression,
            $this->tableName,
            $where,
            $options['limit'] ?? 5,
        );

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue(':'.$key, $value);
        }

        $statement->execute();

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $result) {
            yield new VectorDocument(
                id: $result['id'],
                vector: new Vector($this->fromPgvector($result['embedding'])),
                metadata: new Metadata(json_decode($result['metadata'] ?? '{}', true, 512, \JSON_THROW_ON_ERROR)),
                score: $result['score'],
            );
        }
    }

    private function toPgvector(VectorInterface $vector): string
    {
        return '['.implode(',', $vector->getData()).']';
    }

    /**
     * @return list<float>
     */
    private function fromPgvector(string $vector): array
    {
        return json_decode($vector, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Converts a query-level filter into a SQL clause over the metadata JSONB
     * column. The parameter values are appended to $params, values are never
     * interpolated into the SQL string.
     *
     * @param array<string, string|int|float> $params
     */
    private function convertFilter(FilterInterface $filter, array &$params): string
    {
        return match (true) {
            $filter instanceof AndFilter => $this->convertCompositeFilter($filter->getFilters(), ' AND ', $params),
            $filter instanceof OrFilter => $this->convertCompositeFilter($filter->getFilters(), ' OR ', $params),
            $filter instanceof EqualFilter => \sprintf('%s = :%s', $this->convertFieldAccess($filter->getField(), $filter->getValue()), $this->registerFilterParameter($filter->getValue(), $params)),
            $filter instanceof NotEqualFilter => \sprintf('%s IS DISTINCT FROM :%s', $this->convertFieldAccess($filter->getField(), $filter->getValue()), $this->registerFilterParameter($filter->getValue(), $params)),
            $filter instanceof InFilter => $this->convertInFilter($filter, $params),
            $filter instanceof GreaterThanFilter => \sprintf('%s > :%s', $this->convertFieldAccess($filter->getField(), $filter->getValue()), $this->registerFilterParameter($filter->getValue(), $params)),
            $filter instanceof GreaterThanOrEqualFilter => \sprintf('%s >= :%s', $this->convertFieldAccess($filter->getField(), $filter->getValue()), $this->registerFilterParameter($filter->getValue(), $params)),
            $filter instanceof LessThanFilter => \sprintf('%s < :%s', $this->convertFieldAccess($filter->getField(), $filter->getValue()), $this->registerFilterParameter($filter->getValue(), $params)),
            $filter instanceof LessThanOrEqualFilter => \sprintf('%s <= :%s', $this->convertFieldAccess($filter->getField(), $filter->getValue()), $this->registerFilterParameter($filter->getValue(), $params)),
            default => throw new UnsupportedFeatureException(\sprintf('Filter type "%s" is not supported by store "%s".', $filter::class, self::class)),
        };
    }

    /**
     * @param list<FilterInterface>           $filters
     * @param array<string, string|int|float> $params
     */
    private function convertCompositeFilter(array $filters, string $operator, array &$params): string
    {
        $clauses = [];

        foreach ($filters as $filter) {
            $clauses[] = $this->convertFilter($filter, $params);
        }

        return '('.implode($operator, $clauses).')';
    }

    /**
     * @param array<string, string|int|float> $params
     */
    private function convertInFilter(InFilter $filter, array &$params): string
    {
        $placeholders = [];

        foreach ($filter->getValues() as $value) {
            $placeholders[] = ':'.$this->registerFilterParameter($value, $params);
        }

        return \sprintf('%s IN (%s)', $this->convertFieldAccess($filter->getField(), $filter->getValues()[0]), implode(', ', $placeholders));
    }

    /**
     * Builds the JSONB access expression for a metadata field, cast based on
     * the compared value type. The field name is validated as identifier to
     * prevent SQL injection.
     *
     * Casts are guarded by jsonb_typeof() so that a row holding a different
     * JSON type in the field evaluates to NULL (a non-match) instead of
     * aborting the whole query with a cast error.
     */
    private function convertFieldAccess(string $field, string|int|float|bool $value): string
    {
        if (1 !== preg_match('/^[a-zA-Z0-9_.\\-]+$/', $field)) {
            throw new InvalidArgumentException(\sprintf('Invalid filter field name "%s".', $field));
        }

        $access = \sprintf("metadata->>'%s'", $field);

        if (\is_int($value) || \is_float($value)) {
            return \sprintf("(CASE WHEN jsonb_typeof(metadata->'%s') = 'number' THEN (%s)::numeric END)", $field, $access);
        }

        if (\is_bool($value)) {
            return \sprintf("(CASE WHEN jsonb_typeof(metadata->'%s') = 'boolean' THEN (%s)::boolean END)", $field, $access);
        }

        return $access;
    }

    /**
     * @param array<string, string|int|float> $params
     */
    private function registerFilterParameter(string|int|float|bool $value, array &$params): string
    {
        $name = 'metadata_filter_'.\count($params);

        if (\is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        $params[$name] = $value;

        return $name;
    }
}
