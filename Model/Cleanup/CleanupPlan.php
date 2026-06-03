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
        return count($this->distinctObjectKeys(ObjectPlan::ACTION_DELETE));
    }

    public function totalPreserveCount(): int
    {
        return count($this->distinctObjectKeys(ObjectPlan::ACTION_PRESERVE))
            + count($this->distinctTransformationIds());
    }

    /**
     * Distinct "(type, id)" keys across all rows for the given action. Shared objects
     * (e.g. a per-store auth referenced by multiple Magento rows) collapse to one key
     * so the summary line counts what will actually happen.
     *
     * @return string[]
     */
    protected function distinctObjectKeys(string $action): array
    {
        $seen = [];
        foreach ($this->rows as $row) {
            foreach ($row->objects as $type => $plan) {
                if ($plan->action === $action && $plan->id !== null) {
                    $seen["$type|{$plan->id}"] = true;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * @return string[]
     */
    protected function distinctTransformationIds(): array
    {
        $seen = [];
        foreach ($this->rows as $row) {
            foreach ($row->preservedTransformationIds as $id) {
                $seen[$id] = true;
            }
        }
        return array_keys($seen);
    }
}
