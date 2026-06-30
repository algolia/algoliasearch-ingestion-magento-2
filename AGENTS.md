# AGENTS.md

## Project Overview

Algolia Ingestion API support for Magento 2 (`Algolia_Ingestion`). Routes indexing operations from the core extension through the [Algolia Ingestion API](https://www.algolia.com/doc/rest-api/ingestion/) instead of writing directly to Algolia indices, unlocking pre-indexing JavaScript transformations, low-latency Collections, and observability. Implemented as a per-store send strategy (`IngestionSendStrategy`) contributed to the core extension's strategy resolver, backed by a discover-or-create task pipeline cached in the `algoliasearch_ingestion_task` table. Disabled by default; when off, the core extension writes directly to Algolia.

**Requirements:** PHP 8.3-8.5, Magento 2.4+, `algolia/algoliasearch-magento-2` ^3.19.0 (the send-strategy interface that enables delivery routing was introduced in that release).

This module sequences after `Algolia_AlgoliaSearch` and reuses its credentials (Application ID and Admin API Key).

## Verification (local — no Magento environment needed)

- **`php -l <file>`** — Syntax-check modified PHP files
- **`composer validate`** — Verify `composer.json` correctness
- **`magento2-lint <path>`** — PHP-CS-Fixer (requires `composer global require algolia/magento2-tools`)
- **`magento2-analyse <path>`** — PHPStan level 1 (same global install)
- **`magento2-test <path>`** — Run all quality checks in dry-run mode

### Validating Changes Without Tests

This repo is a Magento 2 extension — unit tests require a full Magento environment (Docker or otherwise) and cannot be run from this checkout alone. When tests are unavailable:

- Follow existing patterns in the same directory for new/modified classes
- Verify DI wiring in `etc/di.xml` when adding new classes, interfaces, plugins, or virtual types (the `TaskInvalidationObserver` invalidators are wired as virtual types)
- Ensure PSR-4 namespace alignment (`Algolia\Ingestion\<path>`)
- Confirm `etc/db_schema.xml` and `etc/db_schema_whitelist.json` stay consistent for schema changes
- Run `php -l` on all modified PHP files

## Testing (requires Magento environment)

Tests run from the Magento root so they use the same `vendor/` directory, executing against this package via the `vendor/algolia/algoliasearch-ingestion-magento-2` symlink. Edit tests under `Test/Unit/` here; run them from the Magento root.

**Unit tests**:
```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml \
  vendor/algolia/algoliasearch-ingestion-magento-2/Test/Unit
```

The unit suite is the behavioural source of truth for several contracts that must not regress, notably:

- **Disabled-task handling** — `IngestionSendStrategyTest` (fallback on/off routing) and `IngestionTaskServiceTest::testDiscoveryThrowsWhenMagentoCandidateIsDisabledEvenIfMerchantIsEnabled`. See [README.md](README.md#disabling-a-task-in-the-dashboard).
- **404 recovery** — stale task invalidate-and-retry-once in `IngestionSendStrategyTest` and `IngestionTaskServiceTest`.
- **Provenance (`origin`)** — `resolveOrigin` branch coverage and destination/source naming-format contract tests in `IngestionTaskServiceTest`.
- **API cleanup safety** — origin matrix, shared-reference demotion, and execute ordering in `IngestionCleanupServiceTest`.

When changing any of these areas, update the corresponding test in the same change.

## Architecture

See the **How It Works** section of [README.md](README.md#how-it-works) for the send strategy, the Source → Task → Transformation → Destination pipeline, task resolution/caching, provenance, and disabled-task behaviour. There is no separate `doc/ARCHITECTURE.md` in this module; the README is the canonical overview.

**Namespace:** PSR-4 root `Algolia\Ingestion` (registered in `registration.php`). Module name: `Algolia_Ingestion`.

Key components:

- `Service/IngestionSendStrategy.php` — the per-store `SendStrategyInterface` implementation; action grouping, temp vs production routing, 404 retry, fallback.
- `Service/IngestionTaskService.php` — task discovery/creation, caching, provenance (`origin`), `isTaskUsable`/`TaskDisabledException`.
- `Service/IngestionClientProvider.php` — builds the region-scoped `IngestionClient` per store.
- `Service/IngestionCleanupService.php` + `Model/Cleanup/*` — provenance-aware `reset --api-cleanup` planning and execution.
- `Console/Command/Ingestion/*` — `init`, `status`, `reset` CLI commands.
- `Observer/TaskInvalidationObserver.php` + `Plugin/IndexDeletionPlugin.php` — keep the local cache in sync with config and index changes.

## Code Style

- PSR-2 base with additional rules in `.php-cs-fixer.php` (where present); match the surrounding code's formatting
- Comments should be rare and only when logic isn't self-descriptive — prefer renaming classes/methods over adding comments
- Do not add PHPDoc blocks that merely restate PHP type declarations. Only add PHPDoc when it provides information beyond the type signature (e.g. `@throws`, descriptions of non-obvious behaviour, or types that cannot be expressed natively such as `array<string, int>`)
- PHPStan level 1 compliance
- MEQP2 marketplace standard — ERRORs block merge, WARNINGs should be avoided
