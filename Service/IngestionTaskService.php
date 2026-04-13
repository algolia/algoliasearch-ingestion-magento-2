<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
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
        protected ConfigHelper $algoliaConfigHelper,
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
            [$destinations, $nbPages] = $this->extractListResponse($response, 'destinations');

            foreach ($destinations as $destination) {
                $dest = $this->normalizeDestination($destination);
                if ($dest['owner'] !== null) {
                    continue;
                }
                if ($dest['indexName'] !== $indexName) {
                    continue;
                }

                $tasksResponse = $client->listTasks(null, null, null, null, null, ['push'], [$dest['destinationID']]);
                [$tasks] = $this->extractListResponse($tasksResponse, 'tasks');

                if (!empty($tasks)) {
                    $taskId = $this->normalizeTask($tasks[0])['taskID'];
                    $this->persistTask($storeId, $indexName, $taskId, null, null);
                    return $taskId;
                }

                // Destination exists but has no push task - create source + task only
                $sourceId = $this->getSource($client, $storeId);
                $taskId = $this->createTask($client, $sourceId, $dest['destinationID']);
                $this->persistTask($storeId, $indexName, $taskId, $sourceId, $dest['destinationID']);
                return $taskId;
            }

            $page++;
        } while ($page <= $nbPages);

        return null;
    }

    /**
     * Create a full push pipeline (source + authentication + destination + task) for the
     * given store and index. Returns the new task UUID.
     * @throws AlreadyExistsException
     */
    protected function createFullPipeline(IngestionClient $client, int $storeId, string $indexName): string
    {
        $sourceId = $this->getSource($client, $storeId);
        $authId   = $this->getAuthentication($client, $storeId);

        $destResponse = $client->createDestination([
            'type'             => 'search',
            'name'             => 'magento-' . $storeId . '-' . $indexName,
            'input'            => ['indexName' => $indexName],
            'authenticationID' => $authId,
        ]);
        $destId = $this->normalizeDestination($destResponse)['destination_id'];

        $taskId = $this->createTask($client, $sourceId, $destId);
        $this->persistTask($storeId, $indexName, $taskId, $sourceId, $destId, $authId);
        return $taskId;
    }

    protected function getAuthentication(IngestionClient $client, int $storeId): string
    {
        $existingId = $this->findExistingAuthentication($client, $storeId);
        if ($existingId !== null) {
            return $existingId;
        }

        $response = $client->createAuthentication([
            'type'  => 'algolia',
            'name'  => 'magento-' . $storeId,
            'input' => [
                'appID'  => $this->algoliaConfigHelper->getApplicationID($storeId),
                'apiKey' => $this->algoliaConfigHelper->getAPIKey($storeId),
            ],
        ]);

        return $this->normalizeAuthentication($response)['authenticationID'];
    }

    protected function findExistingAuthentication(IngestionClient $client, int $storeId): ?string
    {
        $authName = 'magento-' . $storeId;
        $page = 1;

        do {
            $response = $client->listAuthentications(self::DESTINATIONS_PAGE_SIZE, $page, ['algolia']);
            [$authentications, $nbPages] = $this->extractListResponse($response, 'authentications');

            foreach ($authentications as $authentication) {
                $auth = $this->normalizeAuthentication($authentication);
                if ($auth['name'] === $authName) {
                    return $auth['authenticationID'];
                }
            }

            $page++;
        } while ($page <= $nbPages);

        return null;
    }

    protected function getSource(IngestionClient $client, int $storeId): string
    {
        $existingId = $this->findExistingSource($client, $storeId);
        if ($existingId !== null) {
            return $existingId;
        }

        $response = $client->createSource([
            'type' => 'push',
            'name' => 'magento-' . $storeId,
        ]);
        return $response->getSourceID();
    }

    protected function findExistingSource(IngestionClient $client, int $storeId): ?string
    {
        $sourceName = 'magento-' . $storeId;
        $page = 1;

        do {
            $response = $client->listSources(self::DESTINATIONS_PAGE_SIZE, $page, ['push']);
            [$sources, $nbPages] = $this->extractListResponse($response, 'sources');

            foreach ($sources as $source) {
                $src = $this->normalizeSource($source);
                if ($src['name'] === $sourceName) {
                    return $src['sourceID'];
                }
            }

            $page++;
        } while ($page <= $nbPages);

        return null;
    }

    /**
     * Normalize a list API response that may be either a typed response object
     * or a plain associative array. Returns [items[], nbPages].
     */
    protected function extractListResponse($response, string $itemsKey): array
    {
        if (is_array($response)) {
            $items = $response[$itemsKey] ?? [];
            $nbPages = $response['pagination']['nbPages'] ?? 1;
            return [$items, $nbPages];
        }

        $getter = 'get' . ucfirst($itemsKey);
        $items = $response->{$getter}();
        $nbPages = $response->getPagination()->getNbPages() ?? 1;
        return [$items, $nbPages];
    }

    protected function normalizeDestination($destination): array
    {
        if (is_array($destination)) {
            return [
                'destinationID' => $destination['destinationID'] ?? null,
                'owner' => $destination['owner'] ?? null,
                'indexName' => $destination['input']['indexName'] ?? null,
            ];
        }

        return [
            'destinationID' => $destination->getDestinationID(),
            'owner' => $destination->getOwner(),
            'indexName' => $destination->getInput()->getIndexName(),
        ];
    }

    protected function normalizeSource($source): array
    {
        if (is_array($source)) {
            return [
                'sourceID' => $source['sourceID'] ?? null,
                'name' => $source['name'] ?? null,
            ];
        }

        return [
            'sourceID' => $source->getSourceID(),
            'name' => $source->getName(),
        ];
    }

    protected function normalizeTask($task): array
    {
        if (is_array($task)) {
            return ['taskID' => $task['taskID'] ?? null];
        }

        return ['taskID' => $task->getTaskID()];
    }

    protected function normalizeAuthentication($authentication): array
    {
        if (is_array($authentication)) {
            return [
                'authenticationID' => $authentication['authenticationID'] ?? null,
                'name'             => $authentication['name'] ?? null,
            ];
        }

        return [
            'authenticationID' => $authentication->getAuthenticationID(),
            'name'             => $authentication->getName(),
        ];
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
        ?string $destinationId,
        ?string $authenticationId = null
    ): void {
        $task = $this->taskFactory->create();
        $task->setData([
            'store_id'          => $storeId,
            'index_name'        => $indexName,
            'task_id'           => $taskId,
            'source_id'         => $sourceId,
            'destination_id'    => $destinationId,
            'authentication_id' => $authenticationId,
        ]);
        $this->taskResource->save($task);
    }
}
