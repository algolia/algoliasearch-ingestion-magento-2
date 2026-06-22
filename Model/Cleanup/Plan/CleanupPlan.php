<?php

namespace Algolia\Ingestion\Model\Cleanup\Plan;

/**
 * Plan-side root of the cleanup pipeline. Built by IngestionCleanupService::buildPlan(),
 * rendered as a preview, then executed.
 *
 * ```
 * Composition:
 *   CleanupPlan
 *     └─ rows: RowPlan[]
 *          ├─ objects: ObjectPlan[]
 *          │           (keyed by task | source | destination | authentication)
 *          └─ preservedTransformationIds: string[]
 *```
 *
 * Executing the plan produces a {@see \Algolia\Ingestion\Model\Cleanup\Result\CleanupResult}
 * with a parallel row shape on the outcome side.
 */
class CleanupPlan
{
    /**
     * @param RowPlan[] $rows
     * @param int[] $storeIds Empty array means "all stores".
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $storeIds,
        public readonly \DateTimeImmutable $checkedAt
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->rows);
    }
}
