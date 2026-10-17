CHANGELOG
=========

0.15
----

 * Add the bridge
 * Add `Store`, `EntityLoader` and `EntityVectorDocument` to keep vectors in a column of the table of an entity and query for entities
 * Add `Attribute\AiEmbeddable` and `Attribute\AiEmbeddableField` to declare what is embedded, rendered by the new `EntityContentRenderer`
 * `EntityDocument::fromEntity()` falls back to the `#[AiEmbeddable]` attributes when an entity does not implement `EmbeddableEntityInterface`
 * Add `Store::count()`
 * Add `Mirror\MirroredEntityLoader` to render `#[AiEmbeddable]` entities into documents for any store
 * Add `Mirror\MirrorSubscriber` to keep such a store in sync with entity changes
 * Add `Mirror\EntityHydrator` and `Mirror\MirroredEntityRetriever` to resolve search results back to managed entities
