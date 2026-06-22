<?php

namespace Algolia\Ingestion\Test\Unit\Helper;

use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Helper\CleanupPlanCounter;
use Algolia\Ingestion\Model\Cleanup\Plan\CleanupPlan;
use Algolia\Ingestion\Model\Cleanup\Plan\ObjectPlan;
use Algolia\Ingestion\Model\Cleanup\Plan\RowPlan;
use Algolia\Ingestion\Model\IngestionTask;

class CleanupPlanCounterTest extends TestCase
{
    public function testEmptyPlanReportsZeroCounts(): void
    {
        $plan = new CleanupPlan([], [], new \DateTimeImmutable());

        $this->assertTrue($plan->isEmpty());
        $this->assertSame(0, CleanupPlanCounter::distinctDeleteCount($plan));
        $this->assertSame(0, CleanupPlanCounter::distinctPreserveCount($plan));
    }

    public function testDistinctDeleteCountDedupsSharedObjectAcrossRows(): void
    {
        // Two rows that both reference the same shared source. The count must collapse
        // the duplicate (type, id) entry so the summary line tells the truth.
        $row1 = $this->buildRow([
            RowPlan::OBJECT_TASK           => ObjectPlan::delete('task-1'),
            RowPlan::OBJECT_SOURCE         => ObjectPlan::delete('source-shared'),
            RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete('dest-1'),
            RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete('auth-1'),
        ]);
        $row2 = $this->buildRow([
            RowPlan::OBJECT_TASK           => ObjectPlan::delete('task-2'),
            RowPlan::OBJECT_SOURCE         => ObjectPlan::delete('source-shared'),
            RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete('dest-2'),
            RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete('auth-2'),
        ]);

        $plan = new CleanupPlan([$row1, $row2], [], new \DateTimeImmutable());

        // Raw object count would be 8 (4 per row x 2 rows). After dedup the shared
        // source-shared collapses to 1 -> 7 distinct (type, id) pairs.
        $this->assertSame(7, CleanupPlanCounter::distinctDeleteCount($plan));
    }

    public function testDistinctPreserveCountDedupsSharedObjectAcrossRows(): void
    {
        // Mirrors the screenshot scenario: three Magento rows all preserve the same
        // per-store auth. The summary should count it once, not three times.
        $rows = array_map(fn($i) => $this->buildRow([
            RowPlan::OBJECT_TASK           => ObjectPlan::delete("task-$i"),
            RowPlan::OBJECT_SOURCE         => ObjectPlan::delete("source-$i"),
            RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete("dest-$i"),
            RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::preserve('auth-shared', 'still referenced by external destination'),
        ]), [1, 2, 3]);

        $plan = new CleanupPlan($rows, [], new \DateTimeImmutable());

        // Raw preserve count would be 3 (one auth entry per row). Deduped: 1.
        $this->assertSame(1, CleanupPlanCounter::distinctPreserveCount($plan));
    }

    public function testDistinctPreserveCountDedupesTransformationsByIdAcrossRows(): void
    {
        // If two rows happen to surface the same transformation ID (the same
        // transformation attached to two destinations), the count should still
        // report it once.
        $row1 = $this->buildRow(
            objects: [
                RowPlan::OBJECT_TASK           => ObjectPlan::delete('task-1'),
                RowPlan::OBJECT_SOURCE         => ObjectPlan::delete('source-1'),
                RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete('dest-1'),
                RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete('auth-1'),
            ],
            preservedTransformationIds: ['trf-shared', 'trf-1']
        );
        $row2 = $this->buildRow(
            objects: [
                RowPlan::OBJECT_TASK           => ObjectPlan::delete('task-2'),
                RowPlan::OBJECT_SOURCE         => ObjectPlan::delete('source-2'),
                RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete('dest-2'),
                RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete('auth-2'),
            ],
            preservedTransformationIds: ['trf-shared', 'trf-2']
        );

        $plan = new CleanupPlan([$row1, $row2], [], new \DateTimeImmutable());

        // trf-shared, trf-1, trf-2 -> 3 distinct transformations.
        $this->assertSame(3, CleanupPlanCounter::distinctPreserveCount($plan));
    }

    public function testDistinctDeleteCountIgnoresNullIds(): void
    {
        // A DELETE plan with a null id can occur in defensive paths. It must not
        // crash and must not contribute to the count.
        $row = $this->buildRow([
            RowPlan::OBJECT_TASK           => ObjectPlan::delete('task-1'),
            RowPlan::OBJECT_SOURCE         => ObjectPlan::delete(null),
            RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete('dest-1'),
            RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete('auth-1'),
        ]);

        $plan = new CleanupPlan([$row], [], new \DateTimeImmutable());

        $this->assertSame(3, CleanupPlanCounter::distinctDeleteCount($plan));
    }

    /**
     * @param array<string, ObjectPlan> $objects
     * @param string[] $preservedTransformationIds
     */
    private function buildRow(array $objects, array $preservedTransformationIds = []): RowPlan
    {
        return new RowPlan(
            $this->createMock(IngestionTask::class),
            1,
            'idx',
            1,
            'Magento',
            $objects,
            $preservedTransformationIds
        );
    }
}
