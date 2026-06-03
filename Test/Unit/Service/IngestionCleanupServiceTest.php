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
        $this->assertTrue($row->getObject(RowPlan::OBJECT_AUTHENTICATION)->isDelete());
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

        // Destination + auth must not be attempted after source failure
        $this->client->expects($this->never())->method('deleteDestination');
        $this->client->expects($this->never())->method('deleteAuthentication');

        // Local row must not be invalidated when remote delete failed
        $this->taskService->expects($this->never())->method('invalidateRow');

        $plan = new CleanupPlan([$rowPlan], [self::STORE_ID], new \DateTimeImmutable());
        $result = $this->service->execute($plan);

        $this->assertTrue($result->rows[0]->isFailure());
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
}
