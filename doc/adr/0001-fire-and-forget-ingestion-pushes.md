# ADR 0001: Ingestion pushes are fire-and-forget

- **Status:** Accepted
- **Date:** 2026-08-06
- **Applies to:** [`Service/IngestionSendStrategy.php`](../../Service/IngestionSendStrategy.php)
- **Related:** [MAGE-1520](https://algolia.atlassian.net/browse/MAGE-1520) (epic), [MAGE-1542](https://algolia.atlassian.net/browse/MAGE-1542) (integration testing)

## Context

`IngestionSendStrategy` replaces the core extension's direct `batch()` write with a push to the Algolia Ingestion API. The core extension's own write path is synchronous in the sense that matters to callers: a `batch()` response carries a Search `taskID`, and `AlgoliaConnector` records it so a later `waitLastTask()` can block until Algolia has applied the write.

The Ingestion path has no equivalent. A push response carries `runID` and `eventID` and never a `taskID`. The client's response model exposes exactly `runID`, `eventID`, `data`, `events`, `message`, and `createdAt` (`vendor/algolia/algoliasearch-client-php/lib/Model/Ingestion/WatchResponse.php`). A real logged response, with `watch` omitted:

```
Ingestion pushTask response {"taskId":"77074916-093e-4b31-8925-9de653a5665e","storeId":1,
"indexName":"magento2_warden_default_products","action":"addObject",
"runID":"8cdd1a8e-cf51-4334-8a7e-001158be503f","eventID":"d120b75d-0f59-4d50-9b4b-279c3eb69f2e",
"message":"OK","createdAt":"2026-07-14T03:54:46Z"}
```

The leading `taskId` is the Ingestion **Task ID** we pushed to. No field in that response can be passed to `waitForTask`.

Three distinct things are called a task in this area, and conflating them produces confident wrong conclusions in both directions:

| | What it is | Identifier | Waitable by us |
|---|---|---|---|
| **Ingestion Task** | pipeline resource binding a source to a destination; a configuration object, not a unit of work | the `taskID` we push to | nothing to wait for |
| **Run / Event** | the ingestion service's unit of work on our push | `runID`, `eventID` | yes, via `watch: true` or the observability endpoints |
| **Search task** | the search engine applying a write to an index | assigned inside Algolia | no, never returned to us |

The absence of a waitable identifier is not the absence of a guarantee. `watch: true` waits on the run, and a run that completes without error has left the records queryable (see [The synchronous path](#the-synchronous-path)). What is unavailable is the *core-style* wait, where the caller holds a Search task ID and blocks on it.

The consequence in core is mechanical rather than designed: `setLastOperationInfo()` reads `ALGOLIA_API_TASK_ID` from the response and stores `null` for an Ingestion push (`Service/AlgoliaConnector.php`, in `algolia/algoliasearch-magento-2`). Waits on that path therefore become no-ops.

## Decision

**Ingestion pushes are asynchronous by default, and that is the intended contract, not an omission.**

Observability of an ingestion run belongs to the Algolia Dashboard by design. Whether a run succeeds is not the extension's concern: if ingestion fails, that is an ingestion problem, and from the integration's perspective the extension has already done its job by delivering the records to the pipeline.

The client makes async the default. `pushTask($taskID, $payload, $watch = null)` sends the `watch` query parameter only when `$watch !== null`, so passing `null` omits it entirely and the API returns as soon as the records are accepted.

### When a wait is permitted

A wait is added only where **the extension takes a subsequent action predicated on the push having landed**.

Today that is exactly one place: the temporary-index push that precedes `moveIndex`. [`pushToTemporaryIndex()`](../../Service/IngestionSendStrategy.php#L112-L129) hardcodes `watch: true` for that reason, and the inline comment on the argument states it.

Anything else is out of contract. "A reviewer would find the wait reassuring" is not a subsequent action.

### The synchronous path

`setSynchronousMode()` exists so an integration test can assert on records that would otherwise be subject to eventual consistency. It is a test seam, marked `@internal`, and it does not change the production default, which remains `null`.

**A synchronous run that returns without error leaves the pushed records queryable.** Verified by manual testing (2026-08-07). This is what makes the integration suite a straightforward assert-after-wait: it enables synchronous mode in `setUp()`, runs the batch, and asserts on the resulting hit count and record contents directly, with no polling or retry. Nothing in the suite needs to tolerate eventual consistency.

Note the scope of that result: it covers the success path. It says nothing about what a failed or partially failed run returns, which is the subject of the known gap below.

`setSynchronousMode()` must be reset to `null` (not `false`) in `tearDown`, because the client omits the query parameter only on `null`. A static that is never reset leaks synchronous pushes into every later test in the same PHPUnit process.

## Consequences

**Accepted:**

- Indexing throughput is not gated on ingestion run duration.
- Per-operation failures surface in the Dashboard's runs and events views rather than in Magento. Where the merchant needs Magento to keep indexing regardless, the **Fallback to direct indexing** setting covers delivery failures, which are the failures the extension can actually observe.
- Records are not queryable when an asynchronous push returns, so nothing may assert on index contents immediately after one. This is a property of the default path, not a defect. The integration suite sidesteps it entirely by enabling synchronous mode, which does give read-after-write; see [The synchronous path](#the-synchronous-path).

**Not a latency regression:** with the queue active in production, product and page full reindexes both route through the temporary index, which already pushes with `watch: true`. Synchronous pushes are an existing production path, so a synchronous config field would extend a latency class that already ships rather than introducing a new one.

**Known gap, tracked separately:** the one watched push is the one whose result is load-bearing, and nothing inspects it. `pushToTemporaryIndex()` returns the response straight through; `logPushResponse()` only logs it; core's only consumer reads a `taskID` that Ingestion never returns. Core does guard against promoting an incomplete temporary index (`Model/Queue.php`, the `MOVE_INDEX_METHOD_NAME` check in `run()`), but that guard is keyed on `noOfFailedJobs`, which increments only when `processJob()` throws. A push that fails without throwing marks its job successful and the guard passes vacuously.

That gap rests on one unverified premise: whether a watched run that fails or partially fails returns 2xx with the failure carried in `events`/`message`, or a 4xx/5xx that makes the client throw and the guard fire correctly. Settle it empirically before filing. The 2026-08-07 read-after-write result does not bear on it, because that result covers runs returning without error and this is about runs that do not. If the failure signal is body-carried, the fix is to inspect the watched response on the temporary-index path before the move. This is a product bug in its own right and is not a reason to revisit the decision above.

## Rejected alternatives

**Strategy-owned wait semantics.** Retain the `runID` on the strategy, expose a wait operation that polls the run, and have `waitLastTask()` delegate to the resolved strategy. Rejected: run polling plus a `runID`-to-`taskID` bridge plus a new wait member on `SendStrategyInterface` is a new subsystem, built to solve a problem the design deliberately moved to the Dashboard. It would also change a core interface to serve one strategy.

**Synchronous by default.** Rejected as the wrong trade: it adds latency to every push in production to solve a problem that only exists in tests. A config field (default off) is a legitimate separate feature and orthogonal to this decision.

**Treating the null `lastTaskInfoByStore` overwrite as a production defect.** Every bare `waitLastTask($storeId)` call site in core has its `setSettings` on the immediately preceding line, so no record push can interleave, and the one remaining site passes an explicit task ID. The overwrite is real but observable only in tests, where a push is deliberately placed between a clear and a wait. Do not cite it as a production issue.

## Open question

**Does `watch` have a duration bound?** The API reference says only that "the API will wait for the ingestion to be finished before responding" and never states what happens when a run outlives the request. If the API can return early and signal that in `message`, then inspecting the watched response (the known gap above) is also the mitigation, which strengthens the case for doing it. Answerable in the same spike as the known gap, by capturing the status and full body for an oversized payload.

### Resolved

**Does a finished run mean the records are queryable?** Yes, on the success path. Recorded because the reasoning that says otherwise is easy to reconstruct and was in fact reconstructed during review: the connector's write into the destination index is a Search-side operation, no Search task ID appears anywhere in `WatchResponse` or `Event`, and the event types (`fetch`, `record`, `log`, `transform`) include nothing representing an applied index write. That is suggestive, and it is wrong. Manual testing (2026-08-07) confirmed read-after-write for a synchronous run returning without error. Do not reopen this on the strength of the response model alone.

## References

- [Algolia Ingestion REST API: push](https://www.algolia.com/doc/rest-api/ingestion/push)
- [README: How It Works](../../README.md#how-it-works)
- Unit contracts that pin related behaviour: `Test/Unit/Service/IngestionSendStrategyTest.php` (fallback routing, 404 retry)
