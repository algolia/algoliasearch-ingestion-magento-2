# CHANGE LOG

## 0.2.1
- Documentation-only release. No functional changes from 0.2.0. The 0.2.0 tag was cut before the 0.2.0 change log entries below were written, and published stable versions on Packagist are immutable, so the completed change log ships here instead.

## 0.2.0

### Breaking Changes
- `Service\IngestionClientProvider` now extends the core `Algolia\AlgoliaSearch\Service\AbstractClientProvider` and constructor parameter order has changed; any subclass must update its own `parent::__construct()` call. Aligns with the client-provider refactor in core 3.19.0 ([#24](https://github.com/algolia/algoliasearch-ingestion-magento-2/pull/24), companion to core [#1973](https://github.com/algolia/algoliasearch-magento-2/pull/1973)). Requires `algolia/algoliasearch-magento-2:^3.19.0`.

### Added
- Integration test coverage for ingestion-routed indexing of products, categories and CMS pages, asserting that records reach the Algolia index through the task pipeline and that a configured transformation is applied on the way ([#25](https://github.com/algolia/algoliasearch-ingestion-magento-2/pull/25)).
- `IngestionSendStrategy::setSynchronousMode()`, an internal test-only hook that makes `pushTask` block so integration tests can assert on indexed records. Not part of the public API; production pushes remain asynchronous ([#25](https://github.com/algolia/algoliasearch-ingestion-magento-2/pull/25)).
- [ADR 0001](doc/adr/0001-fire-and-forget-ingestion-pushes.md), recording that ingestion pushes are deliberately fire-and-forget, the response-shape constraint that rules out a `waitForTask`-style wait, and the alternatives rejected ([#27](https://github.com/algolia/algoliasearch-ingestion-magento-2/pull/27)). The **How It Works** section of the README now cross-references it.

### Internal
- Unit test suite migrated to PHPUnit 12 attribute style, retaining PHPUnit 10.5 compatibility. Adds a package-level `phpstan.neon` that pins level 1 and suppresses the `AllowMockObjectsWithoutExpectations` attribute error raised when the surrounding Magento install resolves PHPUnit 10.5 ([#26](https://github.com/algolia/algoliasearch-ingestion-magento-2/pull/26)).

## 0.1.0
- Initial release. Algolia Ingestion API support for Magento 2 (`Algolia_Ingestion`), routing product indexing through the Algolia Ingestion API to unlock pre-indexing JavaScript transformations, low-latency Collections, and per-operation observability. Aligned with `algolia/algoliasearch-magento-2` 3.19.0-beta.1.
