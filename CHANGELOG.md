# CHANGE LOG

## 0.2.0

### Breaking Changes
- `Service\IngestionClientProvider` now extends the core `Algolia\AlgoliaSearch\Service\AbstractClientProvider` and constructor parameter order has changed; any subclass must update its own `parent::__construct()` call. Aligns with the client-provider refactor in core 3.19.0 ([#24](https://github.com/algolia/algoliasearch-ingestion-magento-2/pull/24), companion to core [#1973](https://github.com/algolia/algoliasearch-magento-2/pull/1973)). Requires `algolia/algoliasearch-magento-2:^3.19.0`.

## 0.1.0
- Initial release. Algolia Ingestion API support for Magento 2 (`Algolia_Ingestion`), routing product indexing through the Algolia Ingestion API to unlock pre-indexing JavaScript transformations, low-latency Collections, and per-operation observability. Aligned with `algolia/algoliasearch-magento-2` 3.19.0-beta.1.
