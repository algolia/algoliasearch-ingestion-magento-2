<?php

namespace Algolia\Ingestion\Helper;

use Algolia\Ingestion\Model\Cleanup\CleanupPlan;
use Algolia\Ingestion\Model\Cleanup\ObjectPlan;

/**
 * Stateless counts derived from a CleanupPlan. Lives outside the plan itself so the
 * model class stays a pure value carrier; the dedup logic that turns row-local plans
 * into summary totals is a separate concern.
 */
class CleanupPlanCounter
{
    public static function distinctDeleteCount(CleanupPlan $plan): int
    {
        return count(self::distinctObjectKeys($plan, ObjectPlan::ACTION_DELETE));
    }

    public static function distinctPreserveCount(CleanupPlan $plan): int
    {
        return count(self::distinctObjectKeys($plan, ObjectPlan::ACTION_PRESERVE))
            + count(self::distinctTransformationIds($plan));
    }

    /**
     * Distinct "(type, id)" keys across all rows for the given action. Shared objects
     * (e.g. a per-store auth referenced by multiple Magento rows) collapse to one key
     * so the summary line counts what will actually happen.
     *
     * @return string[]
     */
    private static function distinctObjectKeys(CleanupPlan $plan, string $action): array
    {
        $seen = [];
        foreach ($plan->rows as $row) {
            foreach ($row->objects as $type => $objectPlan) {
                if ($objectPlan->action === $action && $objectPlan->id !== null) {
                    $seen["$type|{$objectPlan->id}"] = true;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * @return string[]
     */
    private static function distinctTransformationIds(CleanupPlan $plan): array
    {
        $seen = [];
        foreach ($plan->rows as $row) {
            foreach ($row->preservedTransformationIds as $id) {
                $seen[$id] = true;
            }
        }
        return array_keys($seen);
    }
}
