Doctrine Store
==============

Connects Doctrine ORM entities with Symfony AI Store. It offers two ways to do that. Both share the
same way of describing what gets embedded.

Which one to use
----------------

| | Unified Store | Mirror |
| --- | --- | --- |
| Where the vector lives | In a column of the entity's own table | In any `StoreInterface` store |
| What a query returns | Entities with their score, in one query | Documents, resolved to entities by a hydrator |
| Staying in sync | Nothing to sync, the vector is part of the row | A Doctrine subscriber re-indexes on every change |
| Database | PostgreSQL + pgvector, MariaDB, MySQL, SQLite + sqlite-vec | Whatever the chosen store runs on |
| Filtering | SQL against the entity's columns | The filters of the chosen store |

Pick the Unified Store when the entities already live in a database that can hold vectors. The
embedding is covered by the same transaction and the same schema tooling as the rest of the row.

Pick the Mirror when the vectors have to live elsewhere, for example in Meilisearch, Qdrant or a
managed service. The entity stays the source of truth and the store is a searchable copy of it.

Describing an entity
--------------------

An entity says what goes into its embedding in one of two ways. Both work for both approaches.

Implement `EmbeddableEntityInterface`:

```php
use Symfony\AI\Store\Bridge\Doctrine\EmbeddableEntityInterface;

class Article implements EmbeddableEntityInterface
{
    public function getEmbeddableContent(): string
    {
        return \sprintf("%s\n\n%s", $this->title, $this->body);
    }
}
```

Or mark the entity and its fields with attributes. Only declared fields are rendered. A password
hash or a token is never embedded unless it is opted in.

```php
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;

#[AiEmbeddable(title: 'name')]
class Product
{
    #[AiEmbeddableField]
    private string $name;

    #[AiEmbeddableField(label: 'Price in EUR')]
    private float $price;

    // not marked, so never embedded
    private string $internalNotes;

    // getters for the marked fields are required, unless they are public
}
```

A template replaces the field by field rendering:

```php
#[AiEmbeddable(template: 'The movie "{title}" was released in {year}.', title: 'title')]
class Movie { /* ... */ }
```

The `EntityContentRenderer` does the rendering. `EntityDocument::fromEntity()` takes the content from the
first source that exists: the content passed explicitly, `getEmbeddableContent()`, then the attributes.

Unified Store
-------------

The embedding is a field of the entity. A similarity query returns the entities themselves.

| Platform               | Column      | Distances                        | Vector index     |
| ---------------------- | ----------- | -------------------------------- | ---------------- |
| PostgreSQL + pgvector  | `vector(n)` | cosine, euclidean, inner product | HNSW             |
| MariaDB >= 11.7        | `VECTOR(n)` | cosine, euclidean                | `VECTOR INDEX`   |
| MySQL >= 9.0           | `VECTOR(n)` | cosine, euclidean, dot           | none, exact scan |
| SQLite + sqlite-vec    | `TEXT`      | cosine, euclidean                | none, exact scan |

On SQLite only the distance functions come from the extension. The vectors are stored as JSON text.
A schema carrying them can be created and written against stock SQLite. That lets a test suite run
on SQLite while production runs on one of the others.

Other platforms work by passing a `VectorPlatform\VectorPlatformInterface` implementation of your own
to the store.

Register the DBAL type, and teach Doctrine to recognize the column when it introspects the database.
Without the mapping, `doctrine:schema:validate` and `doctrine:migrations:diff` fail on the unknown
database type:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        types:
            vector: Symfony\AI\Store\Bridge\Doctrine\Type\VectorType
        mapping_types:
            vector: vector
```

Declare the vector as a field of the entity, without a size:

```php
use Doctrine\ORM\Mapping as ORM;
use Symfony\AI\Platform\Vector\VectorInterface;

#[ORM\Entity]
class Article implements EmbeddableEntityInterface
{
    #[ORM\Column(type: 'vector', nullable: true)]
    private ?VectorInterface $embedding = null;

    // ...
}
```

The size is stated where the column is created, not in the mapping. Doctrine decides whether a column
changed by comparing the SQL both sides declare. No supported database reports the size of a vector
column when introspecting one. A sized declaration would make every schema diff want to `ALTER` a
column that is already correct. Either let the store size the column and index it:

```php
$store->setup(['dimensions' => 1536]);
```

On PostgreSQL the extension has to exist before any table with a vector column can be created.
That is a step earlier than the store can reach. Run `CREATE EXTENSION IF NOT EXISTS vector` before
the schema tooling, or from a migration.

Or write it into a migration, which suits an application keeping its schema in migrations:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
ALTER TABLE article ADD embedding vector(1536);
CREATE INDEX article_embedding_idx ON article USING hnsw (embedding vector_cosine_ops);
```

Declare that index on the entity as well. An index Doctrine does not know about is an index the
schema tool wants to drop:

```php
#[ORM\Index(name: 'article_embedding_idx', columns: ['embedding'])]
```

Indexing walks the table and writes a vector onto every row:

```php
use Symfony\AI\Store\Bridge\Doctrine\EntityLoader;
use Symfony\AI\Store\Bridge\Doctrine\Store;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\Indexer\SourceIndexer;

$store = new Store($entityManager, Article::class, 'embedding');
$vectorizer = new Vectorizer($platform, 'text-embedding-3-small');
$indexer = new SourceIndexer(new EntityLoader($entityManager), new DocumentProcessor($vectorizer, $store));

$indexer->index(Article::class);
```

Querying returns `EntityVectorDocument`s. Each carries the entity it matched and the distance to the
queried vector:

```php
use Symfony\AI\Store\Query\VectorQuery;

$query = new VectorQuery($vectorizer->vectorize($article->getEmbeddableContent()));

foreach ($store->query($query, ['limit' => 5, 'where' => 'id != :self', 'params' => ['self' => $article->getId()]]) as $document) {
    echo $document->getEntity()->getTitle(), ' ', $document->getScore(), \PHP_EOL;
}
```

The `where` fragment is plain SQL against the entity's own columns. That makes a "more like this"
query possible. It excludes the row the query started from, and can hide unpublished rows the same way.

Mirror
------

The mirror copies each entity into a store of your choice as a `TextDocument`. A subscriber keeps the
copy current. A hydrator turns search results back into managed entities.

Index every entity of a class:

```php
use Symfony\AI\Store\Bridge\Doctrine\Mirror\MirroredEntityLoader;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\Indexer\SourceIndexer;

$loader = new MirroredEntityLoader($entityManager);
$vectorizer = new Vectorizer($platform, 'text-embedding-3-small');
$indexer = new SourceIndexer($loader, new DocumentProcessor($vectorizer, $store));

$indexer->index(Product::class);
```

Each document gets a deterministic id derived from the entity identifier. Indexing the same entity
again replaces its document. The document also carries the entity class and identifier in its
metadata, which is what the hydrator needs later. Only entities with `#[AiEmbeddable]` are mirrored.
Each entity becomes exactly one document, there is no chunking.

Keep the store in sync by registering `MirrorSubscriber`. It re-indexes an entity after it was
persisted or updated and removes its document after it was deleted:

```php
use Symfony\AI\Store\Bridge\Doctrine\Mirror\MirrorSubscriber;
use Symfony\AI\Store\Indexer\DocumentIndexer;

$indexer = new DocumentIndexer(new DocumentProcessor($vectorizer, $store));

$entityManager->getEventManager()->addEventSubscriber(new MirrorSubscriber($loader, $indexer, $store));
```

The embedding call happens during the flush. For write-heavy applications, move the indexing to an
asynchronous Messenger transport.

Retrieve entities with `MirroredEntityRetriever`. A text query goes in, managed entities come out in
ranking order:

```php
use Symfony\AI\Store\Bridge\Doctrine\Mirror\EntityHydrator;
use Symfony\AI\Store\Bridge\Doctrine\Mirror\MirroredEntityRetriever;
use Symfony\AI\Store\Retriever;

$retriever = new MirroredEntityRetriever(
    new Retriever($store, $vectorizer),
    new EntityHydrator($entityManager),
);

$products = $retriever->retrieve('cookware for induction stoves');
```

Options of the call are handed to the store, so limits and filters are the ones your store knows.

The hydrator loads entities per class with one `IN()` query for single column identifiers. Composite
identifiers go through `EntityManager::find()`. Identifiers that are an association, as in
translation or join entities, are supported too.

Results that cannot be resolved are skipped. That covers entities deleted while the store was out of
sync, documents that never came from an entity, and metadata that no longer matches the mapping. Pass
a callback to `EntityHydrator` to observe the skipped documents:

```php
$hydrator = new EntityHydrator($entityManager, function (object $document) use ($logger) {
    $logger->warning('Stale document in vector store', ['id' => $document->getId()]);
});
```

`EntityHydrator::hydrate()` also works on its own, on any iterable of documents.

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/ai/issues) and
   [send Pull Requests](https://github.com/symfony/ai/pulls)
   in the [main Symfony AI repository](https://github.com/symfony/ai)
