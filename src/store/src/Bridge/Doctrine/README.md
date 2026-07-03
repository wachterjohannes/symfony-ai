Doctrine Store
==============

Turns [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html) entities into RAG documents for
Symfony AI Store — and resolves retrieval results back into managed entities.

Mark an entity with `#[AiEmbeddable]` and its fields with `#[AiEmbeddableField]`, then index it like any other
loader source. Only declared fields are rendered, so sensitive columns (password hashes, tokens, PII) are never
embedded unless you opt them in.

Usage
-----

```php
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;

#[AiEmbeddable(title: 'name')]
class Product
{
    public int $id;

    #[AiEmbeddableField]
    public string $name;

    #[AiEmbeddableField(label: 'Price in EUR')]
    public float $price;

    // not marked -> never embedded
    public string $internalNotes;
}
```

```php
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityLoader;

$loader = new DoctrineEntityLoader($entityManager);

foreach ($loader->load(Product::class) as $document) {
    // TextDocument with a deterministic id derived from the entity identifier
}
```

Instead of field-by-field rendering you can provide a template on the class attribute:

```php
#[AiEmbeddable(template: 'The movie "{title}" was released in {year}.', title: 'title')]
class Movie { /* ... */ }
```

Live sync
---------

Register `EntityIndexSubscriber` with the Doctrine event manager to keep the vector store in sync: embeddable
entities are re-indexed on persist/update and removed on delete, upserting by the deterministic document id.
Each record maps to exactly one document (no chunking). For write-heavy applications, dispatch the indexing
work to an asynchronous Messenger transport.

Retrieving entities
-------------------

Documents produced by the loader carry structured identity metadata (entity class and identifier map), so
retrieval results can be resolved back to the actual database records. `DoctrineEntityRetriever` completes the
round trip: a semantic query in, managed entities out.

```php
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityHydrator;
use Symfony\AI\Store\Bridge\Doctrine\DoctrineEntityRetriever;
use Symfony\AI\Store\Retriever;

$retriever = new DoctrineEntityRetriever(
    new Retriever($store, $vectorizer),
    new DoctrineEntityHydrator($entityManager),
);

// list<Product> — managed entities, in similarity ranking order
$products = $retriever->retrieve('cookware for induction stoves', ['maxItems' => 5]);
```

Entities are batch-loaded per class (one `IN()` query for single-column identifiers; composite identifiers are
resolved through the identity-map-aware `EntityManager::find()`), and the ranking order of the store results is
preserved. Identifiers that are (or include) an association (`#[ORM\Id]` on a to-one relation, e.g. translation
or join entities) are supported: the loader stores the scalar identifier value of the associated entity.

Results that cannot be resolved are skipped silently: stale index entries whose entity was deleted while the
store was out of sync, documents that were never indexed from an entity, or identity metadata that no longer
matches the current mapping (e.g. the class is no longer a mapped entity, or the identifier fields changed
since indexing). Pass a callback as second argument to `DoctrineEntityHydrator` to observe skipped documents,
e.g. for logging or store cleanup:

```php
$hydrator = new DoctrineEntityHydrator($entityManager, function (object $document) use ($logger) {
    $logger->warning('Stale document in vector store', ['id' => $document->getId()]);
});
```

`DoctrineEntityHydrator::hydrate()` also works standalone on any iterable of documents, e.g. on the result of a
handcrafted store query.

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/ai/issues) and
   [send Pull Requests](https://github.com/symfony/ai/pulls)
   in the [main Symfony AI repository](https://github.com/symfony/ai)
