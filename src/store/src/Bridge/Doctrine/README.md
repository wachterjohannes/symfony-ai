Doctrine Store
==============

Turns [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html) entities into RAG documents for
Symfony AI Store.

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

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/ai/issues) and
   [send Pull Requests](https://github.com/symfony/ai/pulls)
   in the [main Symfony AI repository](https://github.com/symfony/ai)
