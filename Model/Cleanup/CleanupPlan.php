<?php

namespace Algolia\Ingestion\Model\Cleanup;

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
