<?php

namespace Algolia\Ingestion\Test\Unit\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\Collection;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Algolia\Ingestion\Service\IngestionCleanupService;
use Algolia\Ingestion\Service\IngestionTaskService;
use Algolia\Ingestion\Model\Cleanup\CleanupPlan;
use Algolia\Ingestion\Model\Cleanup\ObjectPlan;
use Algolia\Ingestion\Model\Cleanup\RowPlan;
use Algolia\Ingestion\Model\Cleanup\RowResult;
use PHPUnit\Framework\MockObject\MockObject;

class IngestionCleanupServiceTest extends TestCase
{
    private const STORE_ID = 1;
    private const INDEX_NAME = 'magento2_default_products';
    private const TASK_ID = 'task-uuid';
    private const SOURCE_ID = 'source-uuid';
    private const DESTINATION_ID = 'dest-uuid';
    private const AUTH_ID = 'auth-uuid';

    private null|(IngestionClientProviderInterface&MockObject) $clientProvider = null;
    private null|(IngestionClient&MockObject) $client = null;
    private null|(CollectionFactory&MockObject) $collectionFactory = null;
    private null|(Collection&MockObject) $collection = null;
    private null|(IngestionTaskService&MockObject) $taskService = null;
    private null|(LoggerInterface&MockObject) $logger = null;
    private ?IngestionCleanupService $service = null;

    protected function setUp(): void
    {
        $this->client = $this->createMock(IngestionClient::class);
        $this->clientProvider = $this->createMock(IngestionClientProviderInterface::class);
        $this->clientProvider->method('getClient')->willReturn($this->client);

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->taskService = $this->createMock(IngestionTaskService::class);
        $this->taskService->method('getTaskPipelineName')
            ->willReturnCallback(fn(int $id) => 'Magento (Store ' . $id . ')');

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new IngestionCleanupService(
            $this->clientProvider,
            $this->collectionFactory,
            $this->taskService,
            $this->logger
        );
    }

    // --- buildPlan: origin matrix ---

    public function testBuildPlanForOriginMagentoMarksAllFourForDelete(): void
    {
        $task = $this->mockTaskRow();
        $this->mockCollectionWith([$task]);
        $this->stubNoSharedRefs();
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);

        $this->assertCount(1, $plan->rows);
        $row = $plan->rows[0];
        $this->assertSame(self::TASK_ID, $row->getObject(RowPlan::OBJECT_TASK)->id);
        $this->assertTrue($row->getObject(RowPlan::OBJECT_TASK)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isDelete());
    }

    public function testBuildPlanForOriginAlgoliaPreservesAllRemoteObjects(): void
    {
        $task = $this->mockTaskRow(origin: IngestionTaskService::ORIGIN_ALGOLIA);
        $this->mockCollectionWith([$task]);

        // Algolia-owned rows must not issue any API calls during plan-building
        $this->client->expects($this->never())->method('listTasks');
        $this->client->expects($this->never())->method('listDestinations');
        $this->client->expects($this->never())->method('getDestination');
        $this->client->expects($this->never())->method('getSource');

        $plan = $this->service->buildPlan([self::STORE_ID]);

        $row = $plan->rows[0];
        $this->assertTrue($row->getObject(RowPlan::OBJECT_TASK)->isPreserve());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isPreserve());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isPreserve());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isPreserve());
    }

    public function testBuildPlanForOriginHybridWithMagentoSourcePreservesMerchantDestinationAndAuth(): void
    {
        $task = $this->mockTaskRow(origin: IngestionTaskService::ORIGIN_HYBRID);
        $this->mockCollectionWith([$task]);

        // Source name matches Magento prefix, destination does not
        $this->client->method('getSource')
            ->willReturn(['name' => 'Magento (Store 1) - products']);
        $this->client->method('getDestination')
            ->willReturn(['name' => 'merchant-search-pipeline']);

        $this->stubNoSharedRefs();

        $plan = $this->service->buildPlan([self::STORE_ID]);
        $row = $plan->rows[0];

        $this->assertTrue($row->getObject(RowPlan::OBJECT_TASK)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isPreserve());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isPreserve());
    }

    public function testBuildPlanForOriginHybridWithMagentoDestinationDeletesDestinationAndAuth(): void
    {
        $task = $this->mockTaskRow(origin: IngestionTaskService::ORIGIN_HYBRID);
        $this->mockCollectionWith([$task]);

        // Destination name matches Magento prefix, source does not
        $this->client->method('getSource')
            ->willReturn(['name' => 'Push - 4327fa4d-e89f-4d66']);
        $this->client->method('getDestination')
            ->willReturn(['name' => 'Magento (Store 1) - magento2_default_products']);

        $this->stubNoSharedRefs();
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);
        $row = $plan->rows[0];

        $this->assertTrue($row->getObject(RowPlan::OBJECT_TASK)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isPreserve());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isDelete());
    }

    // --- buildPlan: shared-ref demotion ---

    public function testBuildPlanDemotesSharedSourceToPreserve(): void
    {
        $task = $this->mockTaskRow();
        $this->mockCollectionWith([$task]);

        $this->stubListTasks(externalSourceTask: true);
        $this->client->method('listDestinations')
            ->willReturn(['destinations' => [['destinationID' => self::DESTINATION_ID]]]);
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);
        $row = $plan->rows[0];

        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isPreserve());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isDelete());
    }

    public function testBuildPlanDemotesSharedDestinationToPreserve(): void
    {
        $task = $this->mockTaskRow();
        $this->mockCollectionWith([$task]);

        $this->stubListTasks(externalDestinationTask: true);
        $this->client->method('listDestinations')
            ->willReturn(['destinations' => [['destinationID' => self::DESTINATION_ID]]]);
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);
        $row = $plan->rows[0];

        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isPreserve());
        // Once the destination is preserved (shared with external task), the auth must
        // ALSO be preserved because the preserved destination still references it.
        // Deleting the auth here would break the external task's pipeline.
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isPreserve());
    }

    public function testBuildPlanDemotesSharedAuthToPreserve(): void
    {
        $task = $this->mockTaskRow();
        $this->mockCollectionWith([$task]);

        $this->stubNoSharedTasks();
        // listDestinations(authenticationID=[AUTH_ID]) returns an external destination
        $this->client->method('listDestinations')->willReturn([
            'destinations' => [
                ['destinationID' => self::DESTINATION_ID],
                ['destinationID' => 'external-destination'],
            ],
        ]);
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);
        $row = $plan->rows[0];

        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isDelete());
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isPreserve());
    }

    // --- buildPlan: edge cases ---

    public function testBuildPlanHandlesNullIdsInDbRowWithoutError(): void
    {
        $task = $this->mockTaskRow(sourceId: null, destinationId: null, authId: null);
        $this->mockCollectionWith([$task]);

        $this->client->method('listTasks')->willReturn(['tasks' => [['taskID' => self::TASK_ID]]]);
        $this->client->method('listDestinations')->willReturn(['destinations' => []]);

        $plan = $this->service->buildPlan([self::STORE_ID]);
        $row = $plan->rows[0];

        $this->assertTrue($row->getObject(RowPlan::OBJECT_TASK)->isDelete());
        // Null IDs are marked preserve with a "not recorded locally" reason
        $this->assertTrue($row->getObject(RowPlan::OBJECT_SOURCE)->isPreserve());
        $this->assertNull($row->getObject(RowPlan::OBJECT_SOURCE)->id);
        $this->assertTrue($row->getObject(RowPlan::OBJECT_DESTINATION)->isPreserve());
        $this->assertNull($row->getObject(RowPlan::OBJECT_DESTINATION)->id);
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isPreserve());
        $this->assertNull($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->id);
    }

    public function testBuildPlanIncludesPreservedTransformationsWhenDestinationDeleteIsPlanned(): void
    {
        $task = $this->mockTaskRow();
        $this->mockCollectionWith([$task]);

        $this->stubNoSharedRefs();
        $this->client->method('getDestination')->willReturn([
            'name' => 'Magento (Store 1) - magento2_default_products',
            'transformationIDs' => ['trf-1', 'trf-2'],
        ]);

        $plan = $this->service->buildPlan([self::STORE_ID]);
        $row = $plan->rows[0];

        $this->assertSame(['trf-1', 'trf-2'], $row->preservedTransformationIds);
    }

    // --- buildPlan: transaction-aware multi-row scenarios ---

    public function testBuildPlanDeletesSharedAuthWhenAllReferencingRowsInScope(): void
    {
        // Two Magento rows share auth A1. Both destinations are in our plan -> auth
        // should be deleted (the original bug: each row used to see the other as
        // "external" and preserve the auth indefinitely).
        $row1 = $this->mockTaskRow(
            taskId: 'task-1',
            sourceId: 'source-1',
            destinationId: 'dest-1',
            authId: 'auth-shared',
            indexName: 'idx-1'
        );
        $row2 = $this->mockTaskRow(
            taskId: 'task-2',
            sourceId: 'source-2',
            destinationId: 'dest-2',
            authId: 'auth-shared',
            indexName: 'idx-2'
        );
        $this->mockCollectionWith([$row1, $row2]);

        $this->client->method('listTasks')
            ->willReturnCallback(fn(...$args) => $this->multiRowListTasks($args, [
                'source-1' => [['taskID' => 'task-1']],
                'source-2' => [['taskID' => 'task-2']],
                'dest-1'   => [['taskID' => 'task-1']],
                'dest-2'   => [['taskID' => 'task-2']],
            ]));

        // Both our destinations reference auth-shared -> after dest-ID union, both are
        // in "ours", no external references -> auth deletable.
        $this->client->method('listDestinations')->willReturn([
            'destinations' => [
                ['destinationID' => 'dest-1'],
                ['destinationID' => 'dest-2'],
            ],
        ]);
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);

        $this->assertCount(2, $plan->rows);
        foreach ($plan->rows as $row) {
            $this->assertTrue(
                $row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isDelete(),
                "Auth must be DELETE on row {$row->indexName} when all referencing destinations are in scope"
            );
        }
    }

    public function testBuildPlanPreservesAuthWhenReferencedByDestinationOutsidePlan(): void
    {
        // Same setup as above, but listDestinations(authID) also returns a third
        // destination that's NOT in our plan -> truly external reference -> auth preserved.
        $row1 = $this->mockTaskRow(
            taskId: 'task-1',
            sourceId: 'source-1',
            destinationId: 'dest-1',
            authId: 'auth-shared',
            indexName: 'idx-1'
        );
        $row2 = $this->mockTaskRow(
            taskId: 'task-2',
            sourceId: 'source-2',
            destinationId: 'dest-2',
            authId: 'auth-shared',
            indexName: 'idx-2'
        );
        $this->mockCollectionWith([$row1, $row2]);

        $this->client->method('listTasks')
            ->willReturnCallback(fn(...$args) => $this->multiRowListTasks($args, [
                'source-1' => [['taskID' => 'task-1']],
                'source-2' => [['taskID' => 'task-2']],
                'dest-1'   => [['taskID' => 'task-1']],
                'dest-2'   => [['taskID' => 'task-2']],
            ]));

        $this->client->method('listDestinations')->willReturn([
            'destinations' => [
                ['destinationID' => 'dest-1'],
                ['destinationID' => 'dest-2'],
                ['destinationID' => 'dest-external'],
            ],
        ]);
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);

        foreach ($plan->rows as $row) {
            $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isPreserve());
        }
    }

    public function testBuildPlanRunsAuthCheckAfterDestinationOverrides(): void
    {
        // Order assertion: listTasks(destinationID) (destination shared-ref check)
        // must precede listDestinations(authenticationID) (auth shared-ref check) so
        // the auth check sees the finalized destination delete set.
        $task = $this->mockTaskRow();
        $this->mockCollectionWith([$task]);

        $callLog = [];
        $this->client->method('listTasks')->willReturnCallback(
            function (...$args) use (&$callLog) {
                if (($args[6] ?? null) !== null) {
                    $callLog[] = 'listTasks:destination';
                } elseif (($args[4] ?? null) !== null) {
                    $callLog[] = 'listTasks:source';
                }
                return ['tasks' => [['taskID' => self::TASK_ID]]];
            }
        );
        $this->client->method('listDestinations')->willReturnCallback(
            function () use (&$callLog) {
                $callLog[] = 'listDestinations:auth';
                return ['destinations' => [['destinationID' => self::DESTINATION_ID]]];
            }
        );
        $this->stubNoTransformations();

        $this->service->buildPlan([self::STORE_ID]);

        $destIndex = array_search('listTasks:destination', $callLog, true);
        $authIndex = array_search('listDestinations:auth', $callLog, true);
        $this->assertNotFalse($destIndex, 'destination shared-ref check must be invoked');
        $this->assertNotFalse($authIndex, 'auth shared-ref check must be invoked');
        $this->assertLessThan($authIndex, $destIndex, 'destination check must precede auth check');
    }

    public function testBuildPlanDedupesSharedSourceDeleteWhenTwoRowsTargetSameSource(): void
    {
        // Defensive: if two rows happen to point at the same source (rare but
        // possible), the source survives as a single delete in the count.
        $row1 = $this->mockTaskRow(
            taskId: 'task-1',
            sourceId: 'source-shared',
            destinationId: 'dest-1',
            authId: 'auth-1',
            indexName: 'idx-1'
        );
        $row2 = $this->mockTaskRow(
            taskId: 'task-2',
            sourceId: 'source-shared',
            destinationId: 'dest-2',
            authId: 'auth-2',
            indexName: 'idx-2'
        );
        $this->mockCollectionWith([$row1, $row2]);

        // listTasks(sourceID=[source-shared]) returns BOTH our tasks - they're both ours.
        $this->client->method('listTasks')->willReturnCallback(
            fn(...$args) => $this->multiRowListTasks($args, [
                'source-shared' => [['taskID' => 'task-1'], ['taskID' => 'task-2']],
                'dest-1'        => [['taskID' => 'task-1']],
                'dest-2'        => [['taskID' => 'task-2']],
            ])
        );
        $this->client->method('listDestinations')->willReturn(['destinations' => []]);
        $this->stubNoTransformations();

        $plan = $this->service->buildPlan([self::STORE_ID]);

        // Both rows still mark source-shared as DELETE - the count dedup collapses them.
        $this->assertTrue($plan->rows[0]->getObject(RowPlan::OBJECT_SOURCE)->isDelete());
        $this->assertTrue($plan->rows[1]->getObject(RowPlan::OBJECT_SOURCE)->isDelete());
        // 2 distinct tasks + 1 shared source + 2 distinct destinations + 2 distinct auths = 7
        $this->assertSame(7, $plan->totalDeleteCount());
    }

    // --- execute: delete order and behavior ---

    public function testExecuteIssuesDeletesInTaskSourceDestinationAuthOrder(): void
    {
        $rowPlan = $this->buildExecutableRowPlanWithAllDeletes();

        $callOrder = [];
        $this->client->method('deleteTask')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'task';
        });
        $this->client->method('deleteSource')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'source';
        });
        $this->client->method('deleteDestination')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'destination';
        });
        $this->client->method('deleteAuthentication')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'authentication';
        });

        $plan = new CleanupPlan([$rowPlan], [self::STORE_ID], new \DateTimeImmutable());
        $this->service->execute($plan);

        $this->assertSame(['task', 'source', 'destination', 'authentication'], $callOrder);
    }

    public function testExecuteSkipsApiCallsForPreservedObjects(): void
    {
        $rowPlan = $this->buildRowPlan([
            RowPlan::OBJECT_TASK           => ObjectPlan::delete(self::TASK_ID),
            RowPlan::OBJECT_SOURCE         => ObjectPlan::preserve(self::SOURCE_ID, 'shared'),
            RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete(self::DESTINATION_ID),
            RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::preserve(self::AUTH_ID, 'shared'),
        ]);

        $this->client->expects($this->once())->method('deleteTask');
        $this->client->expects($this->never())->method('deleteSource');
        $this->client->expects($this->once())->method('deleteDestination');
        $this->client->expects($this->never())->method('deleteAuthentication');

        $plan = new CleanupPlan([$rowPlan], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertTrue($result->rows[0]->isSuccess());
    }

    public function testExecuteTreats404AsSuccessAndWipesLocalRow(): void
    {
        $rowPlan = $this->buildExecutableRowPlanWithAllDeletes();

        $this->client->method('deleteTask')->willThrowException(new NotFoundException());
        $this->client->method('deleteSource')->willReturn([]);
        $this->client->method('deleteDestination')->willReturn([]);
        $this->client->method('deleteAuthentication')->willReturn([]);

        $this->taskService->expects($this->once())
            ->method('invalidateRow')
            ->with($rowPlan->task);

        $plan = new CleanupPlan([$rowPlan], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertTrue($result->rows[0]->isSuccess());
        $this->assertSame(4, $result->rows[0]->deletedCount);
    }

    public function testExecuteLeavesLocalRowIntactOnNon404Failure(): void
    {
        $rowPlan = $this->buildExecutableRowPlanWithAllDeletes();

        $this->client->method('deleteTask')->willReturn([]);
        $this->client->method('deleteSource')->willThrowException(new AlgoliaException('server error'));
        // Destination + auth ARE still attempted after the source failure. The four
        // delete types run as independent passes across the plan, so a failure in one
        // type doesn't abort the others — we want to clean up as much as possible.
        $this->client->method('deleteDestination')->willReturn([]);
        $this->client->method('deleteAuthentication')->willReturn([]);

        // Local row must not be invalidated when ANY remote delete failed.
        $this->taskService->expects($this->never())->method('invalidateRow');

        $plan = new CleanupPlan([$rowPlan], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertTrue($result->rows[0]->isFailure());
        // First failure encountered when composing the row result is reported.
        $this->assertSame(RowPlan::OBJECT_SOURCE, $result->rows[0]->failedOnObject);
    }

    public function testExecuteWipesLocalRowEvenWhenNoRemoteDeletesScheduled(): void
    {
        $rowPlan = $this->buildRowPlan([
            RowPlan::OBJECT_TASK           => ObjectPlan::preserve(self::TASK_ID, 'Algolia-owned pipeline'),
            RowPlan::OBJECT_SOURCE         => ObjectPlan::preserve(self::SOURCE_ID, 'Algolia-owned pipeline'),
            RowPlan::OBJECT_DESTINATION    => ObjectPlan::preserve(self::DESTINATION_ID, 'Algolia-owned pipeline'),
            RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::preserve(self::AUTH_ID, 'Algolia-owned pipeline'),
        ], origin: IngestionTaskService::ORIGIN_ALGOLIA);

        $this->client->expects($this->never())->method('deleteTask');
        $this->client->expects($this->never())->method('deleteSource');
        $this->client->expects($this->never())->method('deleteDestination');
        $this->client->expects($this->never())->method('deleteAuthentication');

        $this->taskService->expects($this->once())
            ->method('invalidateRow')
            ->with($rowPlan->task);

        $plan = new CleanupPlan([$rowPlan], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertTrue($result->rows[0]->isSuccess());
        $this->assertSame(0, $result->rows[0]->deletedCount);
    }

    // --- execute: shared-resource dedup + delete ordering across rows ---

    public function testExecuteDedupesSharedAuthDeleteToOneApiCall(): void
    {
        // Three rows share the same auth. Processing one row at a time would issue
        // three deleteAuthentication calls, two of which would fail because the
        // sibling destinations still exist. Running all destination deletes first
        // and then a single auth delete avoids the failures and the redundant calls.
        $row1 = $this->buildRowPlanFor('idx-1', 'task-1', 'source-1', 'dest-1', 'auth-shared');
        $row2 = $this->buildRowPlanFor('idx-2', 'task-2', 'source-2', 'dest-2', 'auth-shared');
        $row3 = $this->buildRowPlanFor('idx-3', 'task-3', 'source-3', 'dest-3', 'auth-shared');

        $this->client->method('deleteTask')->willReturn([]);
        $this->client->method('deleteSource')->willReturn([]);
        $this->client->method('deleteDestination')->willReturn([]);

        $this->client->expects($this->once())
            ->method('deleteAuthentication')
            ->with('auth-shared')
            ->willReturn([]);

        $plan = new CleanupPlan([$row1, $row2, $row3], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertSame(3, $result->successCount());
        $this->assertSame(0, $result->failureCount());
    }

    public function testExecuteDeletesAllDestinationsBeforeAttemptingSharedAuth(): void
    {
        // Order assertion that locks in the bug fix: every destination delete must
        // precede the auth delete, otherwise the auth delete fails with "in-use by
        // destinations [...]".
        $row1 = $this->buildRowPlanFor('idx-1', 'task-1', 'source-1', 'dest-1', 'auth-shared');
        $row2 = $this->buildRowPlanFor('idx-2', 'task-2', 'source-2', 'dest-2', 'auth-shared');

        $callOrder = [];
        $this->client->method('deleteTask')->willReturnCallback(function ($id) use (&$callOrder) {
            $callOrder[] = "task:$id";
        });
        $this->client->method('deleteSource')->willReturnCallback(function ($id) use (&$callOrder) {
            $callOrder[] = "source:$id";
        });
        $this->client->method('deleteDestination')->willReturnCallback(function ($id) use (&$callOrder) {
            $callOrder[] = "destination:$id";
        });
        $this->client->method('deleteAuthentication')->willReturnCallback(function ($id) use (&$callOrder) {
            $callOrder[] = "authentication:$id";
        });

        $plan = new CleanupPlan([$row1, $row2], [self::STORE_ID], new \DateTimeImmutable());
        $this->service->execute($plan);

        $authIndex = array_search('authentication:auth-shared', $callOrder, true);
        $this->assertNotFalse($authIndex, 'authentication delete must be invoked');

        $destinationIndices = array_keys(array_filter(
            $callOrder,
            fn($entry) => str_starts_with($entry, 'destination:')
        ));
        $this->assertNotEmpty($destinationIndices);
        $this->assertLessThan(
            $authIndex,
            max($destinationIndices),
            'every destination delete must complete before the auth delete'
        );
    }

    public function testExecuteFailsAllRowsThatDependOnFailedSharedDelete(): void
    {
        // If a shared object's delete fails, every row that depends on it must be
        // reported FAILED — single source of truth, no false-positive successes.
        $row1 = $this->buildRowPlanFor('idx-1', 'task-1', 'source-shared', 'dest-1', 'auth-1');
        $row2 = $this->buildRowPlanFor('idx-2', 'task-2', 'source-shared', 'dest-2', 'auth-2');

        $this->client->method('deleteTask')->willReturn([]);
        $this->client->method('deleteSource')
            ->willThrowException(new AlgoliaException('source still in use'));
        $this->client->method('deleteDestination')->willReturn([]);
        $this->client->method('deleteAuthentication')->willReturn([]);

        // Neither row should have its local cache invalidated when shared source fails.
        $this->taskService->expects($this->never())->method('invalidateRow');

        $plan = new CleanupPlan([$row1, $row2], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertSame(0, $result->successCount());
        $this->assertSame(2, $result->failureCount());
        foreach ($result->rows as $row) {
            $this->assertSame(RowPlan::OBJECT_SOURCE, $row->failedOnObject);
        }
    }

    public function testExecuteSucceedsAllRowsWhenSharedAuthDeleteSucceeds(): void
    {
        // Mirrors the live-Warden bug report: 3 rows share an auth; with the fix
        // all 3 are reported successful and all local rows are invalidated.
        $row1 = $this->buildRowPlanFor('idx-1', 'task-1', 'source-1', 'dest-1', 'auth-shared');
        $row2 = $this->buildRowPlanFor('idx-2', 'task-2', 'source-2', 'dest-2', 'auth-shared');
        $row3 = $this->buildRowPlanFor('idx-3', 'task-3', 'source-3', 'dest-3', 'auth-shared');

        $this->client->method('deleteTask')->willReturn([]);
        $this->client->method('deleteSource')->willReturn([]);
        $this->client->method('deleteDestination')->willReturn([]);
        $this->client->method('deleteAuthentication')->willReturn([]);

        $invalidated = [];
        $this->taskService->method('invalidateRow')->willReturnCallback(
            function ($task) use (&$invalidated) {
                $invalidated[] = $task;
            }
        );

        $plan = new CleanupPlan([$row1, $row2, $row3], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertSame(3, $result->successCount());
        $this->assertCount(3, $invalidated);
    }

    // --- Helpers ---

    private function mockTaskRow(
        int $origin = IngestionTaskService::ORIGIN_MAGENTO,
        ?string $taskId = self::TASK_ID,
        ?string $sourceId = self::SOURCE_ID,
        ?string $destinationId = self::DESTINATION_ID,
        ?string $authId = self::AUTH_ID,
        int $storeId = self::STORE_ID,
        string $indexName = self::INDEX_NAME
    ): IngestionTask&MockObject {
        $task = $this->createMock(IngestionTask::class);
        $task->method('getData')->willReturnMap([
            ['store_id', null, $storeId],
            ['index_name', null, $indexName],
            ['origin', null, $origin],
            ['task_id', null, $taskId],
            ['source_id', null, $sourceId],
            ['destination_id', null, $destinationId],
            ['authentication_id', null, $authId],
        ]);
        return $task;
    }

    /**
     * @param IngestionTask[] $tasks
     */
    private function mockCollectionWith(array $tasks): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($tasks));

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->service = new IngestionCleanupService(
            $this->clientProvider,
            $this->collectionFactory,
            $this->taskService,
            $this->logger
        );
    }

    private function stubNoSharedRefs(): void
    {
        $this->stubNoSharedTasks();
        $this->client->method('listDestinations')->willReturn([
            'destinations' => [['destinationID' => self::DESTINATION_ID]],
        ]);
    }

    private function stubNoSharedTasks(): void
    {
        $this->stubListTasks();
    }

    /**
     * Resolve a multi-row listTasks() call by looking at which filter axis was passed
     * (sourceID at index 4, destinationID at index 6) and dispatching by the first ID
     * in that array. Lets tests with multiple sources/destinations return different
     * task lists per call.
     *
     * @param array<int, mixed> $args
     * @param array<string, array<int, array<string, mixed>>> $byKey
     * @return array{tasks: array<int, array<string, mixed>>}
     */
    private function multiRowListTasks(array $args, array $byKey): array
    {
        $sourceID = $args[4] ?? null;
        $destinationID = $args[6] ?? null;
        $key = is_array($sourceID) ? ($sourceID[0] ?? null)
            : (is_array($destinationID) ? ($destinationID[0] ?? null) : null);
        return ['tasks' => $byKey[$key] ?? []];
    }

    /**
     * Stub listTasks() so calls filtered by sourceID/destinationID can each be independently
     * marked as "only our own task" or "has an external task". listTasks is invoked twice
     * during plan building (once per filter axis), so a single willReturn would not let us
     * vary the two responses.
     */
    private function stubListTasks(bool $externalSourceTask = false, bool $externalDestinationTask = false): void
    {
        $this->client->method('listTasks')->willReturnCallback(function (
            $itemsPerPage = null,
            $page = null,
            $action = null,
            $enabled = null,
            $sourceID = null,
            $sourceType = null,
            $destinationID = null
        ) use ($externalSourceTask, $externalDestinationTask) {
            if ($sourceID !== null) {
                return $externalSourceTask
                    ? ['tasks' => [['taskID' => 'external-task'], ['taskID' => self::TASK_ID]]]
                    : ['tasks' => [['taskID' => self::TASK_ID]]];
            }
            if ($destinationID !== null) {
                return $externalDestinationTask
                    ? ['tasks' => [['taskID' => 'external-task']]]
                    : ['tasks' => [['taskID' => self::TASK_ID]]];
            }
            return ['tasks' => []];
        });
    }

    private function stubNoTransformations(): void
    {
        $this->client->method('getDestination')->willReturn([
            'name' => 'Magento (Store 1) - magento2_default_products',
            'transformationIDs' => [],
        ]);
    }

    /**
     * @param array<string, ObjectPlan> $objects
     */
    private function buildRowPlan(array $objects, int $origin = IngestionTaskService::ORIGIN_MAGENTO): RowPlan
    {
        $task = $this->mockTaskRow(origin: $origin);
        return new RowPlan(
            $task,
            self::STORE_ID,
            self::INDEX_NAME,
            $origin,
            'Magento',
            $objects
        );
    }

    private function buildExecutableRowPlanWithAllDeletes(): RowPlan
    {
        return $this->buildRowPlan([
            RowPlan::OBJECT_TASK           => ObjectPlan::delete(self::TASK_ID),
            RowPlan::OBJECT_SOURCE         => ObjectPlan::delete(self::SOURCE_ID),
            RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete(self::DESTINATION_ID),
            RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete(self::AUTH_ID),
        ]);
    }

    /**
     * Build a multi-row scenario RowPlan with caller-specified IDs so several plans
     * can coexist with shared resources (e.g. the same auth across rows).
     */
    private function buildRowPlanFor(
        string $indexName,
        string $taskId,
        string $sourceId,
        string $destinationId,
        string $authId
    ): RowPlan {
        $task = $this->mockTaskRow(
            taskId: $taskId,
            sourceId: $sourceId,
            destinationId: $destinationId,
            authId: $authId,
            indexName: $indexName
        );
        return new RowPlan(
            $task,
            self::STORE_ID,
            $indexName,
            IngestionTaskService::ORIGIN_MAGENTO,
            'Magento',
            [
                RowPlan::OBJECT_TASK           => ObjectPlan::delete($taskId),
                RowPlan::OBJECT_SOURCE         => ObjectPlan::delete($sourceId),
                RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete($destinationId),
                RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete($authId),
            ]
        );
    }
}
