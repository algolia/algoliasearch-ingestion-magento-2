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

    public function totalDeleteCount(): int
    {
        return array_sum(array_map(fn(RowPlan $r) => count($r->deletes()), $this->rows));
    }

    public function totalPreserveCount(): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            $count += count($row->preserves()) + count($row->preservedTransformationIds);
        }
        return $count;
    }
}
