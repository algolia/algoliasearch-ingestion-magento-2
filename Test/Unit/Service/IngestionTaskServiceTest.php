<?php

namespace Algolia\Ingestion\Test\Unit\Service;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Algolia\AlgoliaSearch\Service\IndexNameFetcher;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Exception\TaskDisabledException;
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
    private const TMP_INDEX_NAME = 'test_default_products_tmp';
    private const TASK_ID = 'task-uuid-1234';
    private const SOURCE_ID = 'source-uuid-5678';
    private const DESTINATION_ID = 'dest-uuid-9012';
    private const AUTHENTICATION_ID = 'auth-uuid-3456';

    private null|(IngestionClientProviderInterface&MockObject) $clientProvider = null;
    private null|(IngestionClient&MockObject) $ingestionClient = null;
    private null|(IngestionConfigHelper&MockObject) $configHelper = null;
    private null|(ConfigHelper&MockObject) $algoliaConfigHelper = null;
    private null|(IngestionTaskFactory&MockObject) $taskFactory = null;
    private null|(IngestionTask&MockObject) $taskModel = null;
    private null|(IngestionTaskResource&MockObject) $taskResource = null;
    private null|(CollectionFactory&MockObject) $collectionFactory = null;
    private null|(Collection&MockObject) $collection = null;
    private null|(IndexNameFetcher&MockObject) $indexNameFetcher = null;
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

        $this->taskModel = $this->createMock(IngestionTask::class);
        $this->taskFactory->method('create')->willReturnCallback(fn() => $this->taskModel);

        $this->indexNameFetcher = $this->createMock(IndexNameFetcher::class);
        $this->indexNameFetcher->method('getOriginalIndexName')
            ->willReturnCallback(fn(string $name) => preg_replace('/_tmp$/', '', $name));

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new IngestionTaskService(
            $this->clientProvider,
            $this->configHelper,
            $this->algoliaConfigHelper,
            $this->taskFactory,
            $this->taskResource,
            $this->collectionFactory,
            $this->indexNameFetcher,
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

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdDoesNotShareCacheAcrossStores(): void
    {
        $this->setPrivateProperty(
            $this->service,
            'cache',
            [self::STORE_ID => [self::INDEX_NAME => self::TASK_ID]]
        );

        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();
        $this->setupCreatePipelineMocks();
        $this->ingestionClient->expects($this->once())
            ->method('createTask')
            ->willReturn(['taskID' => 'store-2-task']);

        $result = $this->service->getTaskId($this->mockIndexOptions(2, self::INDEX_NAME));

        $this->assertSame('store-2-task', $result);

        $cache = $this->getPrivateProperty($this->service, 'cache');
        $this->assertSame(self::TASK_ID, $cache[self::STORE_ID][self::INDEX_NAME]);
        $this->assertSame('store-2-task', $cache[2][self::INDEX_NAME]);
    }

    public function testGetTaskIdCachesResultAfterFirstResolution(): void
    {
        $this->setupEmptyCollection();
        $this->setupCreatePipelineMocks();
        $this->setupEmptyDestinationList();

        $this->ingestionClient->expects($this->once())
            ->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

        $this->service->getTaskId($this->mockIndexOptions());

        // Second call must not hit API again
        $this->ingestionClient->expects($this->never())->method('listDestinations');
        $this->ingestionClient->expects($this->never())->method('createSource');

        $second = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $second);
    }

    // --- Database cache ---

    public function testGetTaskIdLoadsFromDatabaseWhenCacheMissButRecordExists(): void
    {
        $taskModel = $this->mockPersistedTaskModel(self::TASK_ID);
        $this->setupCollectionReturning($taskModel);

        $this->ingestionClient->method('getTask')->willReturn(['enabled' => true]);
        $this->ingestionClient->expects($this->never())->method('listDestinations');

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdPopulatesMemoryCacheAfterDatabaseLoad(): void
    {
        $taskModel = $this->mockPersistedTaskModel(self::TASK_ID);
        $this->setupCollectionReturning($taskModel);
        $this->ingestionClient->method('getTask')->willReturn(['enabled' => true]);

        $this->service->getTaskId($this->mockIndexOptions());

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
            ->with(self::TASK_ID)
            ->willReturn(['enabled' => true]);

        $this->service->getTaskId($this->mockIndexOptions());
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

        $this->taskResource->expects($this->once())
            ->method('delete')
            ->with($taskModel);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
        $this->assertNotSame('stale-task-id', $result);
    }

    public function testGetTaskIdThrowsTaskDisabledExceptionWhenTaskIsDisabled(): void
    {
        $taskModel = $this->mockPersistedTaskModel(self::TASK_ID);
        $this->setupCollectionReturning($taskModel);

        $this->ingestionClient->method('getTask')
            ->willReturn(['taskID' => self::TASK_ID, 'enabled' => false]);

        // DB record must NOT be deleted when admin has disabled the task
        $this->taskResource->expects($this->never())->method('delete');
        // No discovery or creation should occur
        $this->ingestionClient->expects($this->never())->method('listDestinations');
        $this->ingestionClient->expects($this->never())->method('createSource');
        $this->ingestionClient->expects($this->never())->method('createTask');

        $this->expectException(TaskDisabledException::class);

        $this->service->getTaskId($this->mockIndexOptions());
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

        $result = $this->service->getTaskId($this->mockIndexOptions());

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

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdThrowsTaskDisabledExceptionWhenDiscoveredTaskIsDisabled(): void
    {
        $this->setupEmptyCollection();

        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1
            ));

        // Discovery hits a task on the matching destination, but admin has disabled it
        $this->ingestionClient->method('listTasks')
            ->willReturn([
                'tasks' => [[
                    'taskID' => self::TASK_ID,
                    'sourceID' => self::SOURCE_ID,
                    'destinationID' => self::DESTINATION_ID,
                    'enabled' => false,
                ]],
            ]);

        // Must not persist the disabled task or fall through to creating a parallel one
        $this->taskResource->expects($this->never())->method('save');
        $this->ingestionClient->expects($this->never())->method('createSource');
        $this->ingestionClient->expects($this->never())->method('createTask');

        $this->expectException(TaskDisabledException::class);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testGetTaskIdCreatesTaskForExistingDestinationWithoutPushTask(): void
    {
        $this->setupEmptyCollection();

        // Matching destination exists, but no push task is attached to it
        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1
            ));

        $this->ingestionClient->method('listTasks')
            ->willReturn(['tasks' => []]);

        $this->ingestionClient->method('listSources')
            ->willReturn($this->mockEmptySourceListResponse());

        $this->ingestionClient->expects($this->once())
            ->method('createSource')
            ->willReturn(['sourceID' => self::SOURCE_ID]);

        // Existing destination + authentication must be reused, never re-created
        $this->ingestionClient->expects($this->never())->method('createDestination');
        $this->ingestionClient->expects($this->never())->method('createAuthentication');
        $this->ingestionClient->expects($this->never())->method('listAuthentications');

        $this->ingestionClient->expects($this->once())
            ->method('createTask')
            ->with($this->callback(fn($args) =>
                $args['sourceID'] === self::SOURCE_ID
                && $args['destinationID'] === self::DESTINATION_ID
            ))
            ->willReturn(['taskID' => self::TASK_ID]);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testGetTaskIdSkipsInternalDestinationWithOwner(): void
    {
        $this->setupEmptyCollection();

        // Destination matches the index name but is owned by another system - must be skipped
        $this->ingestionClient->method('listDestinations')
            ->willReturn([
                'destinations' => [[
                    'destinationID' => self::DESTINATION_ID,
                    'input' => ['indexName' => self::INDEX_NAME],
                    'authenticationID' => self::AUTHENTICATION_ID,
                    'owner' => 'internal-magento-system',
                ]],
                'pagination' => ['nbPages' => 1],
            ]);

        $this->ingestionClient->expects($this->never())->method('listTasks');

        $this->setupCreatePipelineMocks();
        $this->ingestionClient->expects($this->once())->method('createDestination');
        $this->ingestionClient->expects($this->once())
            ->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
    }

    // --- Discovery: multi-task selection ---

    public function testDiscoveryPrefersMagentoTaskWhenListedFirst(): void
    {
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-magento', 'src-magento'),
            $this->discoveryTask('task-merchant', 'src-merchant'),
        ]);
        $this->mockSourcesByName([
            'src-magento'  => $this->magentoSourceName(),
            'src-merchant' => 'Merchant Dashboard Source',
        ]);

        $this->expectPersistedTask('task-magento', IngestionTaskService::ORIGIN_MAGENTO);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame('task-magento', $result);
    }

    /**
     * Regression for the bug: listTasks returns the merchant task first, but discovery must still
     * latch onto the Magento-owned task. Selection is deterministic, independent of response order.
     */
    public function testDiscoveryPrefersMagentoTaskWhenListedSecond(): void
    {
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-merchant', 'src-merchant'),
            $this->discoveryTask('task-magento', 'src-magento'),
        ]);
        $this->mockSourcesByName([
            'src-merchant' => 'Merchant Dashboard Source',
            'src-magento'  => $this->magentoSourceName(),
        ]);

        $this->expectPersistedTask('task-magento', IngestionTaskService::ORIGIN_MAGENTO);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame('task-magento', $result);
    }

    public function testDiscoveryFallsBackToFirstTaskWhenNoMagentoSourceExists(): void
    {
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-merchant-1', 'src-merchant-1'),
            $this->discoveryTask('task-merchant-2', 'src-merchant-2'),
        ]);
        $this->mockSourcesByName([
            'src-merchant-1' => 'Merchant Source One',
            'src-merchant-2' => 'Merchant Source Two',
        ]);

        // Magento-named destination with only merchant-owned tasks: legitimate HYBRID, first task wins.
        $this->expectPersistedTask('task-merchant-1', IngestionTaskService::ORIGIN_HYBRID);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame('task-merchant-1', $result);
    }

    public function testDiscoveryThrowsWhenMagentoCandidateIsDisabledEvenIfMerchantIsEnabled(): void
    {
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-merchant', 'src-merchant', enabled: true),
            $this->discoveryTask('task-magento', 'src-magento', enabled: false),
        ]);
        $this->mockSourcesByName([
            'src-merchant' => 'Merchant Dashboard Source',
            'src-magento'  => $this->magentoSourceName(),
        ]);

        // Admin disabled the canonical Magento task: do not silently substitute the merchant task.
        $this->taskResource->expects($this->never())->method('save');
        $this->ingestionClient->expects($this->never())->method('createSource');
        $this->ingestionClient->expects($this->never())->method('createTask');

        $this->expectException(TaskDisabledException::class);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testDiscoveryPicksEnabledMagentoTaskWhenDisabledMerchantIsFirst(): void
    {
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-merchant', 'src-merchant', enabled: false),
            $this->discoveryTask('task-magento', 'src-magento', enabled: true),
        ]);
        $this->mockSourcesByName([
            'src-merchant' => 'Merchant Dashboard Source',
            'src-magento'  => $this->magentoSourceName(),
        ]);

        // A disabled merchant task ahead of the Magento task must not poison discovery.
        $this->expectPersistedTask('task-magento', IngestionTaskService::ORIGIN_MAGENTO);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame('task-magento', $result);
    }

    public function testDiscoverySkipsTaskWhenGetSourceReturns404(): void
    {
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-orphan', 'src-404'),
            $this->discoveryTask('task-magento', 'src-magento'),
        ]);
        $this->ingestionClient->method('getSource')
            ->willReturnCallback(function (string $id): array {
                if ($id === 'src-404') {
                    throw new NotFoundException('source not found');
                }
                return ['name' => $this->magentoSourceName()];
            });

        // A 404 on one candidate's source is treated as "not Magento"; discovery continues.
        $this->expectPersistedTask('task-magento', IngestionTaskService::ORIGIN_MAGENTO);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame('task-magento', $result);
    }

    public function testDiscoveryDoesNotRefetchSourceWhenPersistingMagentoCandidate(): void
    {
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-magento', 'src-magento'),
        ]);

        // Proves the pre-fetched source is threaded through to persistDiscoveredTask: exactly one GET.
        $this->ingestionClient->expects($this->once())
            ->method('getSource')
            ->with('src-magento')
            ->willReturn(['name' => $this->magentoSourceName()]);

        $this->expectPersistedTask('task-magento', IngestionTaskService::ORIGIN_MAGENTO);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame('task-magento', $result);
    }

    public function testDiscoveryDeduplicatesGetSourceForSharedSourceId(): void
    {
        // Two merchant tasks share a sourceID ahead of the Magento task. The shared source must be
        // fetched once (cache hit on the repeat), not once per task.
        $this->setupMagentoDestinationWithTasks([
            $this->discoveryTask('task-merchant-a', 'src-shared'),
            $this->discoveryTask('task-merchant-b', 'src-shared'),
            $this->discoveryTask('task-magento', 'src-magento'),
        ]);

        $calls = [];
        $this->ingestionClient->method('getSource')
            ->willReturnCallback(function (string $id) use (&$calls): array {
                $calls[$id] = ($calls[$id] ?? 0) + 1;
                return ['name' => $id === 'src-magento' ? $this->magentoSourceName() : 'Merchant Shared Source'];
            });

        $this->expectPersistedTask('task-magento', IngestionTaskService::ORIGIN_MAGENTO);

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame('task-magento', $result);
        $this->assertSame(1, $calls['src-shared'], 'shared sourceID should be fetched once');
        $this->assertSame(1, $calls['src-magento'], 'magento sourceID should be fetched once');
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

        $result = $this->service->getTaskId($this->mockIndexOptions());

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

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
    }

    public function testCreateFullPipelineReusesExistingSourceWhenFound(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();

        $this->ingestionClient->method('listSources')
            ->willReturn([
                'sources' => [[
                    'sourceID' => self::SOURCE_ID,
                    'name' => 'Magento (Store ' . self::STORE_ID . ') - products',
                ]],
                'pagination' => ['nbPages' => 1],
            ]);

        $this->ingestionClient->expects($this->never())->method('createSource');

        $this->ingestionClient->method('listAuthentications')
            ->willReturn($this->mockEmptyAuthenticationListResponse());

        $this->ingestionClient->method('createAuthentication')
            ->willReturn(['authenticationID' => self::AUTHENTICATION_ID]);

        $this->ingestionClient->method('createDestination')
            ->willReturn(['destinationID' => self::DESTINATION_ID]);

        $this->ingestionClient->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

        $result = $this->service->getTaskId($this->mockIndexOptions());

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

        $this->service->getTaskId($this->mockIndexOptions());
    }

    // --- Invalidation ---

    public function testInvalidateClearsCacheSlotAndDeletesRow(): void
    {
        $task = $this->createMock(IngestionTask::class);
        $task->method('getData')->willReturnMap([
            ['store_id', null, self::STORE_ID],
            ['index_name', null, self::INDEX_NAME],
        ]);

        $this->setPrivateProperty($this->service, 'cache', [
            self::STORE_ID => [
                self::INDEX_NAME => self::TASK_ID,
                'other_index' => 'other-task-id',
            ],
        ]);

        $this->taskResource->expects($this->once())
            ->method('delete')
            ->with($task);

        $this->service->invalidate($task);

        $cache = $this->getPrivateProperty($this->service, 'cache');
        $this->assertArrayNotHasKey(self::INDEX_NAME, $cache[self::STORE_ID] ?? []);
        $this->assertArrayHasKey('other_index', $cache[self::STORE_ID] ?? []);
    }

    public function testInvalidateByIndexDeletesDatabaseRecord(): void
    {
        $taskModel = $this->mockPersistedTaskModel(self::TASK_ID);
        $this->setupCollectionReturning($taskModel);

        $this->taskResource->expects($this->once())
            ->method('delete')
            ->with($taskModel);

        $this->service->invalidateByIndex($this->mockIndexOptions());
    }

    public function testInvalidateByIndexIsNoopWhenNoDatabaseRecordExists(): void
    {
        $this->setupEmptyCollection();

        $this->taskResource->expects($this->never())->method('delete');

        $this->service->invalidateByIndex($this->mockIndexOptions());
    }

    public function testInvalidateByStoreDeletesAllDatabaseRecordsForStore(): void
    {
        $task1 = $this->createMock(IngestionTask::class);
        $task2 = $this->createMock(IngestionTask::class);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')
            ->willReturn(new \ArrayIterator([$task1, $task2]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $this->service = new IngestionTaskService(
            $this->clientProvider,
            $this->configHelper,
            $this->algoliaConfigHelper,
            $this->taskFactory,
            $this->taskResource,
            $collectionFactory,
            $this->indexNameFetcher,
            $this->logger
        );

        $deleted = [];
        $this->taskResource->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function ($task) use (&$deleted) {
                $deleted[] = $task;
                return $this->taskResource;
            });

        $this->service->invalidateByStore(self::STORE_ID);

        $this->assertSame([$task1, $task2], $deleted);
    }

    // --- Temporary index resolution ---

    public function testGetTaskIdResolvesProductionNameForTempIndex(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();
        $this->setupCreatePipelineMocks();
        $this->ingestionClient->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

        $tempOptions = $this->mockIndexOptions(self::STORE_ID, self::TMP_INDEX_NAME, true);
        $result = $this->service->getTaskId($tempOptions);

        $this->assertSame(self::TASK_ID, $result);

        $cache = $this->getPrivateProperty($this->service, 'cache');
        $this->assertSame(self::TASK_ID, $cache[self::STORE_ID][self::INDEX_NAME] ?? null);
        $this->assertArrayNotHasKey(self::TMP_INDEX_NAME, $cache[self::STORE_ID] ?? []);
    }

    public function testInvalidateByIndexResolvesProductionNameForTempIndex(): void
    {
        $capturedFilters = [];
        $collection = $this->createMock(Collection::class);
        // Harvest the filters used to retrieve the task to invalidate from DB
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function ($field, $value) use (&$capturedFilters, $collection) {
                $capturedFilters[$field] = $value;
                return $collection;
            });
        $emptyTask = $this->createMock(IngestionTask::class);
        $emptyTask->method('getId')->willReturn(null);
        $collection->method('getFirstItem')->willReturn($emptyTask);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $this->setPrivateProperty($this->service, 'collectionFactory', $collectionFactory);

        $tempOptions = $this->mockIndexOptions(self::STORE_ID, self::TMP_INDEX_NAME, true);
        $this->service->invalidateByIndex($tempOptions);

        $this->assertSame(
            self::INDEX_NAME,
            $capturedFilters['index_name'] ?? null,
            'invalidateByIndex must look up rows by the resolved production index name, not the temp name'
        );
    }

    public function testGetTaskIdUsesIndexNameDirectlyForNonTempIndex(): void
    {
        $this->indexNameFetcher = $this->createMock(IndexNameFetcher::class);
        $this->indexNameFetcher->expects($this->never())->method('getOriginalIndexName');

        $this->service = new IngestionTaskService(
            $this->clientProvider,
            $this->configHelper,
            $this->algoliaConfigHelper,
            $this->taskFactory,
            $this->taskResource,
            $this->collectionFactory,
            $this->indexNameFetcher,
            $this->logger
        );

        $this->setPrivateProperty(
            $this->service,
            'cache',
            [self::STORE_ID => [self::INDEX_NAME => self::TASK_ID]]
        );

        $result = $this->service->getTaskId($this->mockIndexOptions());

        $this->assertSame(self::TASK_ID, $result);
    }

    // --- Naming convention contract ---
    //
    // These two tests pin the destination/source naming format explicitly. Every other
    // provenance test derives expected names via reflection on the same methods, which keeps
    // them DRY but means a format regression would not surface there (both sides would drift
    // together). As this is a persistence format contract, pin the contract here so any change
    // to the naming convention fails loudly.

    public function testGetDestinationNameProducesExpectedFormat(): void
    {
        $this->assertSame(
            'Magento (Store 1) - my_index',
            $this->invokeMethod($this->service, 'getDestinationName', [1, 'my_index'])
        );
    }

    public function testGetSourceNameProducesExpectedFormat(): void
    {
        $this->assertSame(
            'Magento (Store 1) - products',
            $this->invokeMethod($this->service, 'getSourceName', [1, 'products'])
        );
        $this->assertSame(
            'Magento (Store 1)',
            $this->invokeMethod($this->service, 'getSourceName', [1, null])
        );
    }

    // --- Provenance tracking ---

    public function testGetOriginLabelMapsAllStates(): void
    {
        $this->assertSame('Magento', IngestionTaskService::getOriginLabel(IngestionTaskService::ORIGIN_MAGENTO));
        $this->assertSame('Hybrid',  IngestionTaskService::getOriginLabel(IngestionTaskService::ORIGIN_HYBRID));
        $this->assertSame('Algolia', IngestionTaskService::getOriginLabel(IngestionTaskService::ORIGIN_ALGOLIA));
        $this->assertSame('Unknown', IngestionTaskService::getOriginLabel(0));
    }

    public function testCreateFullPipelinePersistsOriginMagento(): void
    {
        $this->setupEmptyCollection();
        $this->setupEmptyDestinationList();
        $this->setupCreatePipelineMocks();
        $this->ingestionClient->method('createTask')
            ->willReturn(['taskID' => self::TASK_ID]);

        $this->expectPersistedOrigin(IngestionTaskService::ORIGIN_MAGENTO);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testCreateTaskForExistingDestinationPersistsOriginMagentoWhenDestinationNameMatches(): void
    {
        $this->setupEmptyCollection();

        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1,
                destinationName: $this->magentoDestinationName()
            ));

        $this->ingestionClient->method('listTasks')->willReturn(['tasks' => []]);
        $this->ingestionClient->method('listSources')->willReturn($this->mockEmptySourceListResponse());
        $this->ingestionClient->method('createSource')->willReturn(['sourceID' => self::SOURCE_ID]);
        $this->ingestionClient->method('createTask')->willReturn(['taskID' => self::TASK_ID]);

        $this->expectPersistedOrigin(IngestionTaskService::ORIGIN_MAGENTO);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testCreateTaskForExistingDestinationPersistsOriginHybridWhenDestinationNameDiverges(): void
    {
        $this->setupEmptyCollection();

        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1,
                destinationName: 'Custom Search Pipeline'
            ));

        $this->ingestionClient->method('listTasks')->willReturn(['tasks' => []]);
        $this->ingestionClient->method('listSources')->willReturn($this->mockEmptySourceListResponse());
        $this->ingestionClient->method('createSource')->willReturn(['sourceID' => self::SOURCE_ID]);
        $this->ingestionClient->method('createTask')->willReturn(['taskID' => self::TASK_ID]);

        $this->expectPersistedOrigin(IngestionTaskService::ORIGIN_HYBRID);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testPersistDiscoveredTaskPersistsOriginMagentoWhenBothNamesMatch(): void
    {
        $this->setupEmptyCollection();

        $destinationName = $this->magentoDestinationName();
        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1,
                destinationName: $destinationName
            ));
        $this->ingestionClient->method('listTasks')
            ->willReturn($this->mockTaskListResponseWithTask(self::TASK_ID));
        $this->ingestionClient->method('getDestination')
            ->willReturn([
                'name' => $destinationName,
                'authenticationID' => self::AUTHENTICATION_ID,
            ]);
        $this->ingestionClient->method('getSource')
            ->willReturn(['name' => $this->magentoSourceName()]);

        $this->expectPersistedOrigin(IngestionTaskService::ORIGIN_MAGENTO);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testPersistDiscoveredTaskPersistsOriginAlgoliaWhenNeitherNameMatches(): void
    {
        $this->setupEmptyCollection();

        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1,
                destinationName: 'DASHBOARD_CREATED_DESTINATION'
            ));
        $this->ingestionClient->method('listTasks')
            ->willReturn($this->mockTaskListResponseWithTask(self::TASK_ID));
        $this->ingestionClient->method('getDestination')
            ->willReturn([
                'name' => 'DASHBOARD_CREATED_DESTINATION',
                'authenticationID' => self::AUTHENTICATION_ID,
            ]);
        $this->ingestionClient->method('getSource')
            ->willReturn(['name' => 'Push - 4327fa4d-e89f-4d66-a8be-497fc7574310']);

        $this->expectPersistedOrigin(IngestionTaskService::ORIGIN_ALGOLIA);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testPersistDiscoveredTaskPersistsOriginHybridWhenOnlyDestinationNameMatches(): void
    {
        $this->setupEmptyCollection();

        $destinationName = $this->magentoDestinationName();
        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1,
                destinationName: $destinationName
            ));
        $this->ingestionClient->method('listTasks')
            ->willReturn($this->mockTaskListResponseWithTask(self::TASK_ID));
        $this->ingestionClient->method('getDestination')
            ->willReturn([
                'name' => $destinationName,
                'authenticationID' => self::AUTHENTICATION_ID,
            ]);
        $this->ingestionClient->method('getSource')
            ->willReturn(['name' => 'Push - 4327fa4d-e89f-4d66-a8be-497fc7574310']);

        $this->expectPersistedOrigin(IngestionTaskService::ORIGIN_HYBRID);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    public function testPersistDiscoveredTaskPersistsOriginHybridWhenOnlySourceNameMatches(): void
    {
        $this->setupEmptyCollection();

        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1,
                destinationName: 'Custom Search Pipeline'
            ));
        $this->ingestionClient->method('listTasks')
            ->willReturn($this->mockTaskListResponseWithTask(self::TASK_ID));
        $this->ingestionClient->method('getDestination')
            ->willReturn([
                'name' => 'Custom Search Pipeline',
                'authenticationID' => self::AUTHENTICATION_ID,
            ]);
        $this->ingestionClient->method('getSource')
            ->willReturn(['name' => $this->magentoSourceName()]);

        $this->expectPersistedOrigin(IngestionTaskService::ORIGIN_HYBRID);

        $this->service->getTaskId($this->mockIndexOptions());
    }

    // --- Helpers ---

    private function mockIndexOptions(
        int $storeId = self::STORE_ID,
        string $indexName = self::INDEX_NAME,
        bool $isTemporaryIndex = false,
        ?string $indexSuffix = '_products'
    ): IndexOptionsInterface&MockObject {
        $mock = $this->createMock(IndexOptionsInterface::class);
        $mock->method('getStoreId')->willReturn($storeId);
        $mock->method('getIndexName')->willReturn($indexName);
        $mock->method('isTemporaryIndex')->willReturn($isTemporaryIndex);
        $mock->method('getIndexSuffix')->willReturn($indexSuffix);
        return $mock;
    }

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
        int $nbPages,
        string $authenticationId = self::AUTHENTICATION_ID,
        ?string $destinationName = null
    ): array {
        $destinations = [];
        foreach ($destinationIdToIndexMap as $destinationId => $indexName) {
            $destination = [
                'destinationID' => $destinationId,
                'input' => ['indexName' => $indexName],
                'authenticationID' => $authenticationId,
            ];
            if ($destinationName !== null) {
                $destination['name'] = $destinationName;
            }
            $destinations[] = $destination;
        }

        return [
            'destinations' => $destinations,
            'pagination' => ['nbPages' => $nbPages],
        ];
    }

    private function magentoDestinationName(int $storeId = self::STORE_ID, string $indexName = self::INDEX_NAME): string
    {
        return $this->invokeMethod($this->service, 'getDestinationName', [$storeId, $indexName]);
    }

    private function magentoSourceName(int $storeId = self::STORE_ID, string $entityType = 'products'): string
    {
        return $this->invokeMethod($this->service, 'getSourceName', [$storeId, $entityType]);
    }

    private function expectPersistedOrigin(int $expectedOrigin): IngestionTask&MockObject
    {
        $this->taskModel = $this->createMock(IngestionTask::class);
        $this->taskModel->expects($this->once())
            ->method('setData')
            ->with($this->callback(fn(array $data): bool =>
                ($data['origin'] ?? null) === $expectedOrigin
            ))
            ->willReturnSelf();
        return $this->taskModel;
    }

    /**
     * Wire up a single Magento-named destination on page 1 whose listTasks returns $tasks, with
     * getDestination resolving to the Magento destination name. Used by the multi-task discovery tests.
     *
     * @param array<int, array<string, mixed>> $tasks
     */
    private function setupMagentoDestinationWithTasks(array $tasks): void
    {
        $this->setupEmptyCollection();

        $this->ingestionClient->method('listDestinations')
            ->willReturn($this->mockDestinationListResponse(
                [self::DESTINATION_ID => self::INDEX_NAME],
                nbPages: 1,
                destinationName: $this->magentoDestinationName()
            ));
        $this->ingestionClient->method('listTasks')
            ->willReturn(['tasks' => $tasks]);
        $this->ingestionClient->method('getDestination')
            ->willReturn([
                'name' => $this->magentoDestinationName(),
                'authenticationID' => self::AUTHENTICATION_ID,
            ]);
    }

    /** @return array<string, mixed> */
    private function discoveryTask(string $taskId, string $sourceId, bool $enabled = true): array
    {
        return [
            'taskID' => $taskId,
            'sourceID' => $sourceId,
            'destinationID' => self::DESTINATION_ID,
            'enabled' => $enabled,
        ];
    }

    /**
     * Mock getSource to resolve each sourceID to a source with the given name.
     *
     * @param array<string, string> $sourceIdToName
     */
    private function mockSourcesByName(array $sourceIdToName): void
    {
        $this->ingestionClient->method('getSource')
            ->willReturnCallback(fn(string $id): array => ['name' => $sourceIdToName[$id] ?? 'Unknown Source']);
    }

    private function expectPersistedTask(string $expectedTaskId, int $expectedOrigin): void
    {
        $this->taskModel = $this->createMock(IngestionTask::class);
        $this->taskModel->expects($this->once())
            ->method('setData')
            ->with($this->callback(fn(array $data): bool =>
                ($data['task_id'] ?? null) === $expectedTaskId
                && ($data['origin'] ?? null) === $expectedOrigin
            ))
            ->willReturnSelf();
    }

    private function mockTaskListResponseWithTask(string $taskId): array
    {
        return [
            'tasks' => [
                [
                    'taskID' => $taskId,
                    'sourceID' => self::SOURCE_ID,
                    'destinationID' => self::DESTINATION_ID,
                    'enabled' => true,
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
