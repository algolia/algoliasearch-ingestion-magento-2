<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\IngestionTaskFactory;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;

class IngestionTaskService implements IngestionTaskServiceInterface
{
    protected const DESTINATIONS_PAGE_SIZE = 100;

    /** @var array<int, array<string, string>> */
    protected array $cache = [];

    public function __construct(
        protected IngestionClientProviderInterface $clientProvider,
        protected IngestionConfigHelper $configHelper,
        protected IngestionTaskFactory $taskFactory,
        protected IngestionTaskResource $taskResource,
        protected CollectionFactory $collectionFactory
    ) {}

    /**
     * @throws AlreadyExistsException
     * @throws AlgoliaException
     * @throws \Exception
     */
    public function getTaskId(int $storeId, string $indexName): string
    {
        $cached = $this->loadFromCache($storeId, $indexName);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->clientProvider->getClient($storeId);

        $dbTask = $this->loadFromDatabase($storeId, $indexName);
        if ($dbTask !== null) {
            $taskId = $dbTask->getData('task_id');
            if ($this->verifyTaskExists($client, $taskId)) {
                $this->storeInCache($storeId, $indexName, $taskId);
                return $taskId;
            }
            $this->taskResource->delete($dbTask);
        }

        $taskId = $this->discoverExistingTask($client, $storeId, $indexName)
            ?? $this->createFullPipeline($client, $storeId, $indexName);

        $this->storeInCache($storeId, $indexName, $taskId);
        return $taskId;
    }

    /**
     * @throws \Exception
     */
    public function invalidate(int $storeId, string $indexName): void
    {
        unset($this->cache[$storeId][$indexName]);

        $dbTask = $this->loadFromDatabase($storeId, $indexName);
        if ($dbTask !== null) {
            $this->taskResource->delete($dbTask);
        }
    }

    /**
     * @throws \Exception
     */
    public function invalidateByStoreId(int $storeId): void
    {
        $this->cache[$storeId] = [];

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);
        foreach ($collection as $task) {
            $this->taskResource->delete($task);
        }
    }

    protected function loadFromCache(int $storeId, string $indexName): ?string
    {
        return $this->cache[$storeId][$indexName] ?? null;
    }

    protected function storeInCache(int $storeId, string $indexName, string $taskId): void
    {
        $this->cache[$storeId][$indexName] = $taskId;
    }

    protected function loadFromDatabase(int $storeId, string $indexName): ?IngestionTask
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->addFieldToFilter('index_name', $indexName);
        $item = $collection->getFirstItem();

        return $item->getId() ? $item : null;
    }

    /**
     * Verify the task still exists in the Ingestion API.
     * Returns false on NotFoundException so the caller can recover.
     */
    protected function verifyTaskExists(IngestionClient $client, string $taskId): bool
    {
        try {
            $client->getTask($taskId);
            return true;
        } catch (NotFoundException $e) {
            // NotFoundException (HTTP 404) is thrown by ApiWrapper when the Algolia API returns a 404 response.
            // This means the task no longer exists in the Ingestion API, so the caller should discard the
            // stale reference and discover or create a new one.
            return false;
        }
    }

    /**
     * Lazily paginate listDestinations() to find a public push task for
     * the given index. Returns the task UUID on the first match, null if
     * no matching destination is found across all pages.
     *
     * When a destination exists but has no push task, a source and task
     * are created against the existing destination (reusing any merchant
     * transformations attached to it).
     * @throws AlreadyExistsException
     */
    protected function discoverExistingTask(IngestionClient $client, int $storeId, string $indexName): ?string
    {
        $page = 1;

        do {
            $response = $client->listDestinations(self::DESTINATIONS_PAGE_SIZE, $page, ['search']);
            $nbPages = $response->getPagination()->getNbPages();

            foreach ($response->getDestinations() as $destination) {
                if ($destination->getOwner() !== null) {
                    continue;
                }
                if ($destination->getInput()->getIndexName() !== $indexName) {
                    continue;
                }

                $destId = $destination->getDestinationID();
                $tasksResponse = $client->listTasks(null, null, null, null, null, ['push'], [$destId]);
                $tasks = $tasksResponse->getTasks();

                if (!empty($tasks)) {
                    $taskId = $tasks[0]->getTaskID();
                    $this->persistTask($storeId, $indexName, $taskId, null, null);
                    return $taskId;
                }

                // Destination exists but has no push task - create source + task only
                $sourceId = $this->createSource($client, $storeId);
                $taskId = $this->createTask($client, $sourceId, $destId);
                $this->persistTask($storeId, $indexName, $taskId, $sourceId, $destId);
                return $taskId;
            }

            $page++;
        } while ($page <= $nbPages);

        return null;
    }

    /**
     * Create a full push pipeline (source + destination + task) for the
     * given store and index. Returns the new task UUID.
     * @throws AlreadyExistsException
     */
    protected function createFullPipeline(IngestionClient $client, int $storeId, string $indexName): string
    {
        $sourceId = $this->createSource($client, $storeId);

        $destResponse = $client->createDestination([
            'type' => 'search',
            'name' => 'magento-' . $storeId . '-' . $indexName,
            'input' => ['indexName' => $indexName],
            'transformationIDs' => [],
        ]);
        $destId = $destResponse->getDestinationID();

        $taskId = $this->createTask($client, $sourceId, $destId);
        $this->persistTask($storeId, $indexName, $taskId, $sourceId, $destId);
        return $taskId;
    }

    protected function createSource(IngestionClient $client, int $storeId): string
    {
        $response = $client->createSource([
            'type' => 'push',
            'name' => 'magento-' . $storeId,
        ]);
        return $response->getSourceID();
    }

    protected function createTask(IngestionClient $client, string $sourceId, string $destId): string
    {
        // For Push sources the task-level action field is required by the API schema but is a
        // no-op at runtime. The actual operation type (addObject, deleteObject, etc.) is declared
        // per-request in the push payload body, so a single task supports mixed operations.
        // 'save' is used here as the semantically neutral placeholder.
        $response = $client->createTask([
            'sourceID' => $sourceId,
            'destinationID' => $destId,
            'action' => 'save',
        ]);
        return $response->getTaskID();
    }

    /**
     * @throws AlreadyExistsException
     */
    protected function persistTask(
        int $storeId,
        string $indexName,
        string $taskId,
        ?string $sourceId,
        ?string $destinationId
    ): void {
        $task = $this->taskFactory->create();
        $task->setData([
            'store_id' => $storeId,
            'index_name' => $indexName,
            'task_id' => $taskId,
            'source_id' => $sourceId,
            'destination_id' => $destinationId,
        ]);
        $this->taskResource->save($task);
    }
}
