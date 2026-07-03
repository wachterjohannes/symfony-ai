CHANGELOG
=========

0.11
----

 * Add support for query-level metadata filters, translated to native Qdrant filter objects (`must`/`should`/`must_not`)
 * Normalize the `endpoint` URL and tolerate a missing trailing slash on path-prefixed endpoints

0.6
---

 * [BC BREAK] The `endpointUrl` parameter for `Store` has been removed
 * [BC BREAK] The `apiKey` parameter for `Store` has been removed

0.1
---

 * Add the bridge
