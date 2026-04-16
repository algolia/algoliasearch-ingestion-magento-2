<?php

namespace Algolia\Ingestion\Test\Unit\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
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
    private const AUTHENTICATION_ID = 'auth-uuid-3456';

    private null|(IngestionClientProviderInterface&MockObject) $clientProvider = null;
    private null|(IngestionClient&MockObject) $ingestionClient = null;
    private null|(IngestionConfigHelper&MockObject) $configHelper = null;
    private null|(ConfigHelper&MockObject) $algoliaConfigHelper = null;
    private null|(IngestionTaskFactory&MockObject) $taskFactory = null;
    private null|(IngestionTaskResource&MockObject) $taskResource = null;
    private null|(CollectionFactory&MockObject) $collectionFactory = null;
    private null|(Collection&MockObject) $collection = null;
    private null|(LoggerInterface&MockObject) $logger = null;
    private ?IngestionTaskService $service = null;

    protected function setUp(): void
    {
        $this->ingestionClient = $this->createMock(IngestionClient::class);

        $this->clientProvider = $this->createMock(IngestionClientProviderInterface::class);
        $this->clientProvider->method('getClient')->willReturn($this->ingestionClient);

        $this->configHelper = $this->createMock(IngestionConfigHelper::class);

        $this->algoliaConfigHelper = $this->createMock(ConfigHelper::class);
        $this->algoliaConfigHelper->method('getApplicationID')->willReturn('TEST_APP_ID');
        $this->algoliaConfigHelper->method('getAPIKey')->willReturn('TEST_API_KEY');

        $this->taskFactory = $this->createMock(IngestionTaskFactory::class);
        $this->taskResource = $this->createMock(IngestionTaskResource::class);

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->taskFactory->method('create')->willReturn($this->createMock(IngestionTask::class));

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new IngestionTaskService(
            $this->clientProvider,
            $this->configHelper,
            $this->algoliaConfigHelper,
            $this->taskFactory,
            $this->taskResource,
            $this->collectionFactory,
            $this->logger
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
            ->willReturn(['taskID' => self::TASK_ID]);

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
            ->willReturn(['taskID' => self::TASK_ID]);

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

        $this->ingestionClient->method('getDestination')
            ->willReturn(['authenticationID' => self::AUTHENTICATION_ID]);

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

        $this->ingestionClient->method('getDestination')
            ->willReturn(['authenticationID' => self::AUTHENTICATION_ID]);

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdCreatesFullPipelineWhenNoExistingTaskFound(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();

        $this->ingestionClient->method('listSources')
            ->willReturn($this->mockEmptySourceListResponse());

        $this->ingestionClient->expects($this->once())
            ->method('createSource')
            ->willReturn(['sourceID' => self::SOURCE_ID]);

        $this->ingestionClient->method('listAuthentications')
            ->willReturn($this->mockEmptyAuthenticationListResponse());

        $this->ingestionClient->expects($this->once())
            ->method('createAuthentication')
            ->willReturn(['authenticationID' => self::AUTHENTICATION_ID]);

        $this->ingestionClient->expects($this->once())
            ->method('createDestination')
            ->willReturn(['destinationID' => self::DESTINATION_ID]);

        $this->ingestionClient->expects($this->once())
            ->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testCreateFullPipelineReusesExistingAuthenticationWhenFound(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();

        $this->ingestionClient->method('listSources')
            ->willReturn($this->mockEmptySourceListResponse());

        $this->ingestionClient->method('createSource')
            ->willReturn(['sourceID' => self::SOURCE_ID]);

        $this->ingestionClient->method('listAuthentications')
            ->willReturn($this->mockAuthenticationListResponseWithMatch(
                self::AUTHENTICATION_ID,
                'Magento (Store ' . self::STORE_ID . ')'
            ));

        $this->ingestionClient->expects($this->never())->method('createAuthentication');

        $this->ingestionClient->method('createDestination')
            ->willReturn(['destinationID' => self::DESTINATION_ID]);

        $this->ingestionClient->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

        $result = $this->service->getTaskId(self::STORE_ID, self::INDEX_NAME);

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdPersistsTaskAfterCreation(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();
        $this->setupCreatePipelineMocks();
        $this->ingestionClient->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

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
        $this->ingestionClient->method('listSources')
            ->willReturn($this->mockEmptySourceListResponse());

        $this->ingestionClient->method('createSource')
            ->willReturn(['sourceID' => self::SOURCE_ID]);

        $this->ingestionClient->method('listAuthentications')
            ->willReturn($this->mockEmptyAuthenticationListResponse());

        $this->ingestionClient->method('createAuthentication')
            ->willReturn(['authenticationID' => self::AUTHENTICATION_ID]);

        $this->ingestionClient->method('createDestination')
            ->willReturn(['destinationID' => self::DESTINATION_ID]);
    }

    private function mockDestinationListResponse(
        array $destinationIdToIndexMap,
        int $nbPages
    ): array {
        $destinations = [];
        foreach ($destinationIdToIndexMap as $destinationId => $indexName) {
            $destinations[] = [
                'destinationID' => $destinationId,
                'input' => ['indexName' => $indexName],
            ];
        }

        return [
            'destinations' => $destinations,
            'pagination' => ['nbPages' => $nbPages],
        ];
    }

    private function mockTaskListResponseWithTask(string $taskId): array
    {
        return [
            'tasks' => [
                [
                    'taskID' => $taskId,
                    'sourceID' => self::SOURCE_ID,
                    'destinationID' => self::DESTINATION_ID,
                ],
            ],
        ];
    }

    private function mockEmptySourceListResponse(): array
    {
        return [
            'sources' => [],
            'pagination' => ['nbPages' => 1],
        ];
    }

    private function mockEmptyAuthenticationListResponse(): array
    {
        return [
            'authentications' => [],
            'pagination' => ['nbPages' => 1],
        ];
    }

    private function mockAuthenticationListResponseWithMatch(
        string $authenticationId,
        string $name
    ): array {
        return [
            'authentications' => [
                [
                    'authenticationID' => $authenticationId,
                    'name' => $name,
                ],
            ],
            'pagination' => ['nbPages' => 1],
        ];
    }
}
