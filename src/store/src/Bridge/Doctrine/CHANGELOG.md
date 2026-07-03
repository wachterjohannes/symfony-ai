CHANGELOG
=========

0.11
----

 * Add the bridge
 * Add `DoctrineEntityLoader` to render `#[AiEmbeddable]` entities into RAG documents
 * Add `EntityIndexSubscriber` to keep the vector store in sync with entity changes
 * Add structured identity metadata (`_entity_class`, `_entity_identifier`) to documents produced by `DoctrineEntityLoader`
 * Add `DoctrineEntityHydrator` to resolve retrieval results back to managed entities in ranking order
 * Add `DoctrineEntityRetriever` to answer semantic queries with entities instead of documents
