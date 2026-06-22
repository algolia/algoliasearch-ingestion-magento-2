<?php

namespace Algolia\Ingestion\Model\Cleanup\Result;

/**
 * Result-side root of the cleanup pipeline. Produced by IngestionCleanupService::execute()
 * from a {@see \Algolia\Ingestion\Model\Cleanup\Plan\CleanupPlan}.
 *
 * Composition:
 * ```
 *   CleanupResult
 *     └─ rows: RowResult[]
 *          └─ plan: RowPlan               (back-reference to the planned row whose
 *                                          execution this outcome describes)
 * ```
 */
class CleanupResult
{
    /**
     * @param RowResult[] $rows
     */
    public function __construct(public readonly array $rows) {}

    public function successCount(): int
    {
        return count(array_filter($this->rows, fn(RowResult $r) => $r->isSuccess()));
    }

    public function failureCount(): int
    {
        return count(array_filter($this->rows, fn(RowResult $r) => $r->isFailure()));
    }
}
