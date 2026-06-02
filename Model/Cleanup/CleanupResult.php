<?php

namespace Algolia\Ingestion\Model\Cleanup;

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
