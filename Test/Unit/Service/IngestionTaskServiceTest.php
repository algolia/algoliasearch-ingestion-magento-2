<?php

namespace Algolia\Ingestion\Test\Unit\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Model\Ingestion\Destination;
use Algolia\AlgoliaSearch\Model\Ingestion\DestinationCreateResponse;
use Algolia\AlgoliaSearch\Model\Ingestion\DestinationInput;
use Algolia\AlgoliaSearch\Model\Ingestion\ListDestinationsResponse;
use Algolia\AlgoliaSearch\Model\Ingestion\ListTasksResponse;
use Algolia\AlgoliaSearch\Model\Ingestion\Pagination;
use Algolia\AlgoliaSearch\Model\Ingestion\SourceCreateResponse;
use Algolia\AlgoliaSearch\Model\Ingestion\Task;
use Algolia\AlgoliaSearch\Model\Ingestion\TaskCreateResponse;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\IngestionTaskFactory;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\Collection;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Algolia\Ingestion\Service\IngestionTaskService;
use PHPUnit\Framework\MockObject\MockObject;

class IngestionTaskServiceTest extends TestCase
{
    private const STORE_ID = 1;
    private const INDEX_NAME = 'test_default_products';
    private const TASK_ID = 'task-uuid-1234';
    private const SOURCE_ID = 'source-uuid-5678';
    private const DESTINATION_ID = 'dest-uuid-9012';

    private null|(IngestionClientProviderInterface&MockObject) $clientProvider = null;
    private null|(IngestionClient&MockObject) $ingestionClient = null;
    private null|(IngestionConfigHelper&MockObject) $configHelper = null;
    private null|(IngestionTaskFactory&MockObject) $taskFactory = null;
    private null|(IngestionTaskResource&MockObject) $taskResource = null;
    private null|(CollectionFactory&MockObject) $collectionFactory = null;
    private null|(Collection&MockObject) $collection = null;
    private ?IngestionTaskService $service = null;

    protected function setUp(): void
    {
        $this->ingestionClient = $this->createMock(IngestionClient::class);

        $this->clientProvider = $this->createMock(IngestionClientProviderInterface::class);
        $this->clientProvider->method('getClient')->willReturn($this->ingestionClient);

        $this->configHelper = $this->createMock(IngestionConfigHelper::class);
        $this->taskFactory = $this->createMock(IngestionTaskFactory::class);
        $this->taskResource = $this->createMock(IngestionTaskResource::class);

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->taskFactory->method('create')->willReturn($this->createMock(IngestionTask::class));

        $this->service = new IngestionTaskService(
            $this->clientProvider,
            $this->configHelper,
            $this->taskFactory,
            $this->taskResource,
            $this->collectionFactory
        );
    }

    // --- Memory cache ---

    public function testGetTaskIdReturnsFromMemoryCacheWithoutDbOrApiCalls(): void
    {
        $this->setPrivateProperty(
            $this->service,
            'cache',
            [self::STORE_ID => [self::INDEX_NAME => self::TASK_ID]]
        );

        $this->collectionFactory->expects($this->never())->method('create');
        $this->ingestionClient->expects($this->never())->method('listDestinations');
        $this->ingestionClient->expects($this->never())->method('getTask');

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdCachesResultAfterFirstResolution(): void
    {
        $this->setupEmptyCollection();
        $this->setupCreatePipelineMocks();
        $this->setupEmptyDestinationList();

        $this->ingestionClient->expects($this->once())
            ->method('createTask')
            ->willReturn($this->mockTaskCreateResponse(self::TASK_ID));

        $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        // Second call must not hit API again
        $this->ingestionClient->expects($this->never())->method('listDestinations');
        $this->ingestionClient->expects($this->never())->method('createSource');

        $second = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $second);
    }

    // --- Database cache ---

    public function testGetTaskIdLoadsFromDatabaseWhenCacheMissButRecordExists(): void
    {
        $taskModel = $this->mockPersistedTaskModel(self::TASK_ID);
        $this->setupCollectionReturning($taskModel);

        $this->ingestionClient->method('getTask')->willReturn([]);
        $this->ingestionClient->expects($this->never())->method('listDestinations');

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdPopulatesMemoryCacheAfterDatabaseLoad(): void
    {
        $taskModel = $this->mockPersistedTaskModel(self::TASK_ID);
        $this->setupCollectionReturning($taskModel);
        $this->ingestionClient->method('getTask')->willReturn([]);

        $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $cache = $this->getPrivateProperty($this->service, 'cache');
        $this->assertSame(self::TASK_ID, $cache[self::STORE_ID][self::INDEX_NAME] ?? null);
    }

    // --- API verification of persisted task ---

    public function testGetTaskIdVerifiesApiTaskAfterDatabaseLoad(): void
    {
        $taskModel = $this->mockPersistedTaskModel(self::TASK_ID);
        $this->setupCollectionReturning($taskModel);

        $this->ingestionClient->expects($this->once())
            ->method('getTask')
            ->with(self::TASK_ID);

        $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);
    }

    public function testGetTaskIdRecoversFromStaleDbRecordOnNotFoundException(): void
    {
        $taskModel = $this->mockPersistedTaskModel('stale-task-id');
        $this->setupCollectionReturning($taskModel);

        $this->ingestionClient->method('getTask')
            ->willThrowException(new NotFoundException());

        $this->setupEmptyDestinationList();
        $this->setupCreatePipelineMocks();
        $this->ingestionClient->method('createTask')
            ->willReturn($this->mockTaskCreateResponse(self::TASK_ID));

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
        $this->assertNotSame('stale-task-id', $result);
    }

    // --- Discovery (lazy pagination) ---

    public function testGetTaskIdDiscoversExistingTaskOnFirstPage(): void
    {
        $this->setupEmptyCollection();

        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1
            ));

        $this->ingestionClient->method('listTasks')
            ->willReturn($this->mockTaskListResponseWithTask(self::TASK_ID));

        $this->ingestionClient->expects($this->never())->method('createSource');
        $this->ingestionClient->expects($this->never())->method('createDestination');
        $this->ingestionClient->expects($this->never())->method('createTask');

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdEarlyExitWhenDestinationFoundOnSecondPage(): void
    {
        $this->setupEmptyCollection();

        // Page 1: no matching destination, Page 2: matching destination
        $this->ingestionClient->expects($this->exactly(2))
            ->method('listDestinations')
            ->willReturnOnConsecutiveCalls(
                $this->mockDestinationListResponse(
                    ['other-dest' => 'other_index_name'],
                    nbPages: 2
                ),
                $this->mockDestinationListResponse(
                    [self::DESTINATION_ID => self::INDEX_NAME],
                    nbPages: 2
                )
            );

        $this->ingestionClient->method('listTasks')
            ->willReturn($this->mockTaskListResponseWithTask(self::TASK_ID));

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdCreatesFullPipelineWhenNoExistingTaskFound(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();

        $this->ingestionClient->expects($this->once())
            ->method('createSource')
            ->willReturn($this->mockSourceCreateResponse(self::SOURCE_ID));

        $this->ingestionClient->expects($this->once())
            ->method('createDestination')
            ->willReturn($this->mockDestinationCreateResponse(self::DESTINATION_ID));

        $this->ingestionClient->expects($this->once())
            ->method('createTask')
            ->willReturn($this->mockTaskCreateResponse(self::TASK_ID));

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdPersistsTaskAfterCreation(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();
        $this->setupCreatePipelineMocks();
        $this->ingestionClient->method('createTask')
            ->willReturn($this->mockTaskCreateResponse(self::TASK_ID));

        $newTaskModel = $this->createMock(IngestionTask::class);
        $this->taskFactory->method('create')->willReturn($newTaskModel);

        $this->taskResource->expects($this->once())
            ->method('save')
            ->with($newTaskModel);

        $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);
    }

    // --- Invalidation ---

    public function testInvalidateRemovesSingleCacheEntry(): void
    {
        $this->setupEmptyCollection();
        $this->setPrivateProperty($this->service, 'cache', [
            self::STORE_ID => [
                self::INDEX_NAME => self::TASK_ID,
                'other_index' => 'other-task-id',
            ],
        ]);

        $this->service->invalidate(self::STORE_ID, self::INDEX_NAME);

        $cache = $this->getPrivateProperty($this->service, 'cache');
        $this->assertArrayNotHasKey(self::INDEX_NAME, $cache[self::STORE_ID] ?? []);
        $this->assertArrayHasKey('other_index', $cache[self::STORE_ID] ?? []);
    }

    public function testInvalidateByStoreIdClearsAllEntriesForStore(): void
    {
        $this->setPrivateProperty($this->service, 'cache', [
            self::STORE_ID => [
                self::INDEX_NAME => self::TASK_ID,
                'other_index' => 'other-task-id',
            ],
            2 => [
                'some_index' => 'unrelated-task-id',
            ],
        ]);

        $this->service->invalidateByStoreId(self::STORE_ID);

        $cache = $this->getPrivateProperty($this->service, 'cache');
        $this->assertEmpty($cache[self::STORE_ID] ?? []);
        $this->assertNotEmpty($cache[2] ?? []);
    }

    // --- Helpers ---

    private function setupEmptyCollection(): void
    {
        $emptyTaskModel = $this->createMock(IngestionTask::class);
        $emptyTaskModel->method('getId')->willReturn(null);
        $this->collection->method('getFirstItem')->willReturn($emptyTaskModel);
    }

    private function setupCollectionReturning(IngestionTask $taskModel): void
    {
        $this->collection->method('getFirstItem')->willReturn($taskModel);
    }

    private function mockPersistedTaskModel(string $taskId): IngestionTask&MockObject
    {
        $taskModel = $this->createMock(IngestionTask::class);
        $taskModel->method('getId')->willReturn(1);
        $taskModel->method('getData')->willReturnMap([
            ['task_id', null, $taskId],
            ['source_id', null, self::SOURCE_ID],
            ['destination_id', null, self::DESTINATION_ID],
        ]);

        return $taskModel;
    }

    private function setupEmptyDestinationList(): void
    {
        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse([], nbPages: 1));
    }

    private function setupCreatePipelineMocks(): void
    {
        $this->ingestionClient->method('createSource')
            ->willReturn($this->mockSourceCreateResponse(self::SOURCE_ID));

        $this->ingestionClient->method('createDestination')
            ->willReturn($this->mockDestinationCreateResponse(self::DESTINATION_ID));
    }

    private function mockDestinationListResponse(
        array $destinationIdToIndexMap,
        int $nbPages
    ): ListDestinationsResponse&MockObject {
        $destinations = [];
        foreach ($destinationIdToIndexMap as $destinationId => $indexName) {
            $input = $this->createMock(DestinationInput::class);
            $input->method('getIndexName')->willReturn($indexName);

            $destination = $this->createMock(Destination::class);
            $destination->method('getDestinationID')->willReturn($destinationId);
            $destination->method('getInput')->willReturn($input);

            $destinations[] = $destination;
        }

        $pagination = $this->createMock(Pagination::class);
        $pagination->method('getNbPages')->willReturn($nbPages);

        $response = $this->createMock(ListDestinationsResponse::class);
        $response->method('getDestinations')->willReturn($destinations);
        $response->method('getPagination')->willReturn($pagination);

        return $response;
    }

    private function mockTaskListResponseWithTask(string $taskId): ListTasksResponse&MockObject
    {
        $task = $this->createMock(Task::class);
        $task->method('getTaskID')->willReturn($taskId);

        $response = $this->createMock(ListTasksResponse::class);
        $response->method('getTasks')->willReturn([$task]);

        return $response;
    }

    private function mockTaskCreateResponse(string $taskId): TaskCreateResponse&MockObject
    {
        $response = $this->createMock(TaskCreateResponse::class);
        $response->method('getTaskID')->willReturn($taskId);

        return $response;
    }

    private function mockSourceCreateResponse(string $sourceId): SourceCreateResponse&MockObject
    {
        $response = $this->createMock(SourceCreateResponse::class);
        $response->method('getSourceID')->willReturn($sourceId);

        return $response;
    }

    private function mockDestinationCreateResponse(string $destinationId): DestinationCreateResponse&MockObject
    {
        $response = $this->createMock(DestinationCreateResponse::class);
        $response->method('getDestinationID')->willReturn($destinationId);

        return $response;
    }
}
