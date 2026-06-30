# Algolia Ingestion for Magento 2

A Magento extension that routes Algolia product indexing operations through the [Algolia Ingestion API](https://www.algolia.com/doc/rest-api/ingestion/) instead of writing directly to your Algolia indices.

Routing through Ingestion unlocks platform capabilities that are not available on the direct write path:

- **Pre-indexing JavaScript transformations** authored and managed in the Algolia dashboard.
- **Low latency Collections** support.
- **Observability** of every indexing operation through the Ingestion runs and events views.

When the feature is disabled (the default), the core extension continues to write directly to Algolia and this module stays out of the way.

## How It Works

### The send strategy

The core `algolia/algoliasearch-magento-2` extension (v3.19.0+) resolves a *send strategy* for every indexing operation. This module contributes [`IngestionSendStrategy`](Service/IngestionSendStrategy.php), which becomes applicable on a per-store basis whenever Algolia Ingestion is enabled for that store. Every record operation Magento would normally `batch()` straight to an index is instead grouped by action (`addObject`, `partialUpdateObject`, `deleteObject`, and so on) and pushed to the Ingestion API.

If anything goes wrong and **Fallback to direct indexing** is enabled, the strategy transparently falls back to the core `DirectSendStrategy`, so indexing never silently stops. If fallback is disabled, the error is re-thrown.

### The Task Pipeline

The Ingestion API models indexing as a pipeline of resources. Following the data as it flows from Magento to a searchable index:

```
Source  ──►  Task  ──►  Transformation  ──►  Destination
(push)                  (optional)           (search index)
                                             + Authentication
```

- **Source** - a `push` source that receives records from Magento.
- **Task** - binds a source to a destination and is the resource Magento actually pushes to.
- **Transformation** - optional JavaScript that reshapes records before they land in the index. A transformation is attached to the **destination**, so the same one can be reused across multiple destinations. It can be defined when creating a push connector in the Algolia dashboard, or managed independently afterwards.
- **Destination** - the target Algolia search index, paired with an **Authentication** holding the credentials.

This module never pushes raw records to an index. It resolves a **Task ID** for the (store, index) pair and calls `pushTask()` (or `push()` with a production reference for temporary reindex flows). Resolution is handled by [`IngestionTaskService`](Service/IngestionTaskService.php), which:

1. Checks an in-memory cache, then the `algoliasearch_ingestion_task` MySQL table.
2. On a miss, **discovers** an existing pipeline by listing destinations and tasks that match the extension's naming convention for that index.
3. If no usable pipeline exists, **creates** the missing pieces (source, task, and, where needed, destination and authentication).
4. Persists the resolved Task ID locally so subsequent operations skip the API round trip.

Stale records are self-healing: a `404` from a push invalidates the cached task and retries once before falling back.

### Provenance (the `origin` column)

Because a pipeline can be created by this module, created by a merchant in the dashboard, or a mix of the two, every cached task row records its **origin** as a tri-state value:

| Origin | Meaning |
|---|---|
| **Magento** | The extension created the full pipeline (source, task, and destination). |
| **Hybrid** | The extension attached its own source/task to a destination the merchant already owned. |
| **Algolia** | A fully merchant-owned pipeline that the extension only discovered and reused. |

Provenance is derived by comparing the discovered destination and source names against the format the extension itself would produce. It is surfaced in the `status` command and gates the safety logic of `reset --api-cleanup`: the cleanup only deletes resources it can prove Magento owns, and demotes anything shared with an out-of-scope task to "preserve".

### Disabling a task in the dashboard

A task can be disabled from the Algolia dashboard (`enabled: false`). The integration treats this as a **deliberate, valid state**, not as a missing or broken pipeline, and the behaviour is pinned by unit tests so it does not drift.

The key distinction is against a `404`. A `404` means the task no longer exists, so the stale local reference is discarded and the pipeline is rediscovered or recreated. A *disabled* task still exists and is still valid for when the admin re-enables it, so the local cache reference is **never deleted or replaced**. Instead, a `TaskDisabledException` is raised and a warning is logged telling the admin to re-enable the task in the dashboard.

Because `TaskDisabledException` is a plain `\RuntimeException` (not a `NotFoundException`), it bypasses the `404` retry path and lands directly in the strategy's error handler, which routes purely on the **Fallback to direct indexing** setting:

| Fallback setting | Behaviour when the task is disabled |
|---|---|
| **Enabled** (default) | Falls back to a direct `batch()` write, so indexing continues uninterrupted. |
| **Disabled** | The exception is re-thrown and the operation fails loudly. |

This is intentional: a disabled task does not silently halt indexing unless the admin has explicitly opted out of the fallback.

The contract is asymmetric with discovery on purpose. If the Magento-owned candidate task is disabled, discovery does **not** silently substitute a different merchant-owned task that happens to be enabled. It surfaces the disabled state rather than quietly routing through a pipeline the merchant did not intend Magento to use.

> **Tests:** `IngestionSendStrategyTest::testSendFallsBackToBatchWhenTaskDisabledAndFallbackEnabled` and `testSendThrowsWhenTaskDisabledAndFallbackDisabled` pin the send-time routing; `IngestionTaskServiceTest::testDiscoveryThrowsWhenMagentoCandidateIsDisabledEvenIfMerchantIsEnabled` pins the discovery contract.

## Requirements

- PHP 8.3+ (8.3, 8.4, and 8.5 are supported)
- Magento 2.4+
- `algolia/algoliasearch-magento-2` ^3.19.0

## Installation

### Via Composer (Recommended)

```bash
composer require algolia/algoliasearch-ingestion-magento-2
bin/magento module:enable Algolia_Ingestion
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`setup:upgrade` creates the `algoliasearch_ingestion_task` table used to track the source/destination/task pipeline per store and index.

### Manual Installation

1. Download the module files.
2. Place them in `app/code/Algolia/Ingestion/`.
3. Run the commands above starting from `module:enable`.

## Configuration

All settings live under **Stores > Configuration > Algolia Search > Indexing Manager > Algolia Ingestion** (config path `algoliasearch_indexing_manager/ingestion/*`). Every field is scopable to the default, website, or store view level.

| Field | Path | Default | Description |
|---|---|---|---|
| **Enable Algolia Ingestion** | `./enable` | No | Routes indexing through the Ingestion Platform. When off, indexing is sent directly to Algolia. |
| **Region** | `./region` | `us` | The Ingestion Platform region: America (US) or Europe (EU). See the warning below. |
| **Fallback to direct indexing on error** | `./fallback_to_batch` | Yes | If a push fails, fall back to direct (`batch`) indexing instead of throwing. |

> **Prerequisite:** Algolia credentials must be configured at **Stores > Configuration > Algolia Search > Credentials and Basic Setup** before enabling this module. The Ingestion API reuses the same Application ID and Admin API Key.

Changing the credentials or the region invalidates the cached task pipeline automatically (via config-change observers), so the next indexing operation re-resolves against the new target.

### ⚠️ Setting the wrong region is a silent failure

**The region you select must match the true region of your Algolia application.** This is the single most important setting to get right.

The Ingestion API derives its host purely from the configured region string (`data.{region}.algolia.com`); it never validates that string against your application's actual region. Because of this:

- The region defaults to `us`. A store backed by an **EU** application will inherit `us` unless you change it.
- A mismatched region does **not** produce an error. The mismatched control plane happily mints real Source, Authentication, Destination, and Task UUIDs, and the CLI reports success with those IDs.
- Records still reach the searchable index in the application's home region, so indexing appears to "work".
- However, the **connector configuration is stranded in the wrong region's control plane**. It is invisible and unmanageable in your dashboard, which means you cannot attach transformations or inspect runs and events for a pipeline you cannot see. That is a real loss of functionality with no error to catch.

If you run multiple stores against applications in different regions, set the region explicitly per store scope and verify that each store's connector appears in the corresponding regional dashboard.

## CLI Commands

The module ships three console commands under the `algolia:ingestion:` namespace. Each accepts an optional list of store IDs as positional arguments; with none supplied, the command operates on all (enabled) stores.

### `algolia:ingestion:init`

Proactively warms the task cache for the standard index suffixes (`products`, `categories`, `pages`, `suggestions`) on every enabled store, resolving or creating the pipeline for each. Idempotent: indices that already have a cache entry are skipped. Additional section indices warm lazily on their first push, since they cannot be enumerated statically.

```bash
bin/magento algolia:ingestion:init           # all enabled stores
bin/magento algolia:ingestion:init 1 2       # only stores 1 and 2
```

### `algolia:ingestion:status`

Displays the cached pipeline per store and index: index name, **Origin** (Magento / Hybrid / Algolia), Task ID, and creation timestamp. Read-only.

```bash
bin/magento algolia:ingestion:status
```

### `algolia:ingestion:reset`

Clears the **local** task cache so the next operation re-resolves. By default it modifies nothing in Algolia.

```bash
bin/magento algolia:ingestion:reset          # clear cache for all stores (prompts)
bin/magento algolia:ingestion:reset 2        # clear cache for store 2
bin/magento algolia:ingestion:reset --force  # skip the confirmation prompt
```

Add `--api-cleanup` to also tear down the matching Algolia-side resources (task, source, destination, authentication). This is provenance- and shared-reference-aware:

- It previews the exact delete/preserve plan before doing anything.
- It only deletes resources Magento owns (`Magento` origin), preserving the merchant-owned side of `Hybrid` pipelines and skipping `Algolia` pipelines entirely.
- Resources shared with a task outside the requested scope are demoted to "preserve" so a cleanup never breaks an unrelated pipeline.

```bash
bin/magento algolia:ingestion:reset --api-cleanup        # preview, then confirm
bin/magento algolia:ingestion:reset 2 --api-cleanup --force
```

## Troubleshooting

### Debug logging

The strategy and task service log to the core extension's logger. Enable logging at **Stores > Configuration > Algolia Search > Credentials & Setup > Enable Logging** and watch `var/log/algolia.log` for push responses, `404` retry/invalidation notices, multi-task ambiguity warnings, and fallback events.

### Postman collection

A ready-to-use Postman collection lives in [`postman/`](postman/) for exercising the Ingestion (Connectors) API directly. It covers full CRUD for authentications, destinations, sources, tasks, and transformations, plus the observability endpoints (runs and events). This is the fastest way to confirm what resources actually exist in a given region's control plane (for example, to diagnose a region mismatch) without going through Magento.

See [`postman/README.md`](postman/README.md) for import and setup instructions. Note the collection's `baseUrl` carries the same region segment caveat described above.

## Limitations

- Requires `algolia/algoliasearch-magento-2` ^3.19.0 - the send strategy interface that allows delivery routing was introduced in that release.
- JavaScript transformations must be configured in the Algolia dashboard independently. This module handles delivery to the pipeline; transformation logic lives in Algolia.
- The region is user-declared and is not validated against your application. See the warning under [Configuration](#-setting-the-wrong-region-is-a-silent-failure).

## Further Reading

- [Algolia Ingestion REST API documentation](https://www.algolia.com/doc/rest-api/ingestion/)
- [Postman collection for the Ingestion API](postman/README.md)

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
