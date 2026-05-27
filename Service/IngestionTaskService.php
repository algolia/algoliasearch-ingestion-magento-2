<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Algolia\AlgoliaSearch\Service\IndexNameFetcher;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Exception\TaskDisabledException;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\IngestionTaskFactory;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Magento\Framework\Exception\AlreadyExistsException;

class IngestionTaskService implements IngestionTaskServiceInterface
{
    public const ORIGIN_MAGENTO = 1;
    public const ORIGIN_HYBRID  = 2;
    public const ORIGIN_ALGOLIA = 3;

    protected const DESTINATIONS_PAGE_SIZE = 100;

    /** @var array<int, array<string, string>> */
    protected array $cache = [];

    public static function getOriginLabel(int $origin): string
    {
        return match ($origin) {
            self::ORIGIN_MAGENTO => 'Magento',
            self::ORIGIN_HYBRID  => 'Hybrid',
            self::ORIGIN_ALGOLIA => 'Algolia',
            default              => 'Unknown',
        };
    }

    public function __construct(
        protected IngestionClientProviderInterface $clientProvider,
        protected IngestionConfigHelper            $configHelper,
        protected ConfigHelper                     $algoliaConfigHelper,
        protected IngestionTaskFactory             $taskFactory,
        protected IngestionTaskResource            $taskResource,
        protected CollectionFactory                $collectionFactory,
        protected IndexNameFetcher                 $indexNameFetcher,
        protected LoggerInterface                  $logger
    ) {}

    private function resolveProductionIndexName(IndexOptionsInterface $indexOptions): string
    {
        $indexName = $indexOptions->getIndexName();
        if ($indexOptions->isTemporaryIndex()) {
            return $this->indexNameFetcher->getOriginalIndexName($indexName);
        }
        return $indexName;
    }

    /**
     * @throws AlreadyExistsException
     * @throws AlgoliaException
     * @throws \Exception
     */
    public function getTaskId(IndexOptionsInterface $indexOptions): string
    {
        $storeId = $indexOptions->getStoreId();
        $indexName = $this->resolveProductionIndexName($indexOptions);
        $entityType = ltrim((string) $indexOptions->getIndexSuffix(), '_') ?: null;
        $cached = $this->loadFromCache($storeId, $indexName);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->clientProvider->getClient($storeId);

        $dbTask = $this->loadFromDatabase($storeId, $indexName);
        if ($dbTask !== null) {
            $taskId = $dbTask->getData('task_id');
            if ($this->isTaskUsable($client, $taskId)) {
                $this->storeInCache($storeId, $indexName, $taskId);
                return $taskId;
            }
            $this->logger->info('Cached task ID {taskId} for {indexName} is stale, deleting local record', [
                'taskId'    => $taskId,
                'indexName' => $indexName,
            ]);
            $this->taskResource->delete($dbTask);
        }

        $taskId = $this->discoverExistingTask($client, $storeId, $indexName, $entityType)
            ?? $this->createFullPipeline($client, $storeId, $indexName, $entityType);

        $this->storeInCache($storeId, $indexName, $taskId);
        return $taskId;
    }

    /**
     * @throws \Exception
     */
    public function invalidate(IndexOptionsInterface $indexOptions): void
    {
        $storeId = $indexOptions->getStoreId();
        $indexName = $this->resolveProductionIndexName($indexOptions);
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
     * Returns true when the task is enabled and usable.
     *
     * Pass a task ID to have the method fetch the task via the Ingestion API
     * (returning false on 404), or pass an already-fetched task array to skip
     * the fetch — useful when the caller already has the task in hand (e.g. from
     * listTasks during discovery).
     *
     * @param string|array<string, mixed> $taskOrId
     * @throws TaskDisabledException when the task has enabled=false.
     *         Callers MUST NOT delete or replace any local reference — the task
     *         is intentionally disabled by an admin and the reference is still
     *         valid for if/when they re-enable it.
     */
    protected function isTaskUsable(IngestionClient $client, string|array $taskOrId): bool
    {
        if (is_string($taskOrId)) {
            try {
                $task = $client->getTask($taskOrId);
            } catch (NotFoundException) {
                // 404: task no longer exists in the Ingestion API. Caller should discard
                // the stale reference and rediscover/recreate.
                return false;
            }
        } else {
            $task = $taskOrId;
        }

        if (!$task['enabled']) {
            $this->logger->warning('Algolia task is disabled; re-enable in dashboard to resume ingestion', [
                'taskId' => $task['taskID'],
            ]);
            throw new TaskDisabledException($task['taskID']);
        }

        return true;
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
     * @throws AlgoliaException
     */
    protected function discoverExistingTask(
        IngestionClient $client,
        int $storeId,
        string $indexName,
        ?string $entityType
    ): ?string {
        $page = 1;

        do {
            $response = $client->listDestinations(self::DESTINATIONS_PAGE_SIZE, $page, ['search']);
            $destinations = $response['destinations'] ?? [];
            $nbPages = $response['pagination']['nbPages'] ?? 1;

            foreach ($destinations as $destination) {
                if ($this->isInternal($destination)) {
                    continue;
                }
                if (($destination['input']['indexName'] ?? null) !== $indexName) {
                    continue;
                }

                $tasksResponse = $client->listTasks(
                    itemsPerPage: null,
                    page: null,
                    action: null,
                    enabled: null,
                    sourceID: null,
                    sourceType: ['push'],
                    destinationID: [$destination['destinationID']]
                );
                $tasks = $tasksResponse['tasks'] ?? [];

                if (!empty($tasks) && $this->isTaskUsable($client, $tasks[0])) {
                    return $this->persistDiscoveredTask($client, $storeId, $indexName, $tasks[0], $entityType);
                }

                // Destination exists but has no usable push task — create source + task only
                return $this->createTaskForExistingDestination(
                    $client,
                    $storeId,
                    $indexName,
                    $destination,
                    $entityType
                );
            }

            $page++;
        } while ($page <= $nbPages);

        return null;
    }

    /** Internal tasks and destinations are managed by the Ingestion API and not by the extension. */
    protected function isInternal(array $record): bool
    {
        return !empty($record['owner']);
    }

    /**
     * Create a full push pipeline (source + authentication + destination + task) for the
     * given store and index. Returns the new task UUID.
     * @throws AlreadyExistsException
     */
    protected function createFullPipeline(
        IngestionClient $client,
        int $storeId,
        string $indexName,
        ?string $entityType
    ): string {
        $sourceId = $this->getSource($client, $storeId, $entityType);
        $authId   = $this->getAuthentication($client, $storeId);

        $destResponse = $client->createDestination([
            'type'             => 'search',
            'name'             => $this->getDestinationName($storeId, $indexName),
            'input'            => ['indexName' => $indexName],
            'authenticationID' => $authId,
        ]);
        $destId = $destResponse['destinationID'];

        $taskId = $this->createTask($client, $sourceId, $destId);
        $this->persistTask($storeId, $indexName, $taskId, $sourceId, $destId, $authId, self::ORIGIN_MAGENTO);

        $this->logger->info('Created full ingestion pipeline for {indexName} (store {storeId})', [
            'storeId'          => $storeId,
            'indexName'        => $indexName,
            'taskId'           => $taskId,
            'sourceId'         => $sourceId,
            'destinationId'    => $destId,
            'authenticationId' => $authId,
        ]);

        return $taskId;
    }

    protected function getTaskPipelineName(int $storeId): string
    {
        return 'Magento (Store ' . $storeId . ')';
    }

    protected function getDestinationName(int $storeId, string $indexName): string
    {
        return $this->getTaskPipelineName($storeId) . ' - ' . $indexName;
    }

    /**
     * Compute pipeline provenance by comparing destination and source names against the Magento naming convention.
     *
     * Pass $source = null when the caller created the source itself (and therefore knows it is Magento-owned without
     * an API roundtrip); pass the API-fetched source array otherwise.
     */
    protected function resolveOrigin(
        array $destination,
        ?array $source,
        int $storeId,
        string $indexName,
        ?string $entityType
    ): int {
        $destOurs = ($destination['name'] ?? null) === $this->getDestinationName($storeId, $indexName);
        $sourceOurs = $source === null
            ? true
            : ($source['name'] ?? null) === $this->getSourceName($storeId, $entityType);

        if ($destOurs && $sourceOurs) {
            return self::ORIGIN_MAGENTO;
        }
        if (!$destOurs && !$sourceOurs) {
            return self::ORIGIN_ALGOLIA;
        }
        return self::ORIGIN_HYBRID;
    }

    protected function getAuthentication(IngestionClient $client, int $storeId): string
    {
        $existingId = $this->findExistingAuthentication($client, $storeId);
        if ($existingId !== null) {
            return $existingId;
        }

        $response = $client->createAuthentication([
            'type'  => 'algolia',
            'name'  => $this->getAuthenticationName($storeId),
            'input' => [
                'appID'  => $this->algoliaConfigHelper->getApplicationID($storeId),
                'apiKey' => $this->algoliaConfigHelper->getAPIKey($storeId),
            ],
        ]);

        return $response['authenticationID'];
    }

    protected function findExistingAuthentication(IngestionClient $client, int $storeId): ?string
    {
        $authName = $this->getAuthenticationName($storeId);
        $page = 1;

        do {
            $response = $client->listAuthentications(self::DESTINATIONS_PAGE_SIZE, $page, ['algolia']);
            $authentications = $response['authentications'] ?? [];
            $nbPages = $response['pagination']['nbPages'] ?? 1;

            foreach ($authentications as $authentication) {
                if ($authentication['name'] === $authName) {
                    return $authentication['authenticationID'];
                }
            }

            $page++;
        } while ($page <= $nbPages);

        return null;
    }

    protected function getAuthenticationName(int $storeId): string
    {
        return $this->getTaskPipelineName($storeId);
    }

    protected function getSource(IngestionClient $client, int $storeId, ?string $entityType): string
    {
        $existingId = $this->findExistingSource($client, $storeId, $entityType);
        if ($existingId !== null) {
            return $existingId;
        }

        $response = $client->createSource([
            'type' => 'push',
            'name' => $this->getSourceName($storeId, $entityType),
        ]);
        return $response['sourceID'];
    }

    protected function findExistingSource(IngestionClient $client, int $storeId, ?string $entityType): ?string
    {
        $sourceName = $this->getSourceName($storeId, $entityType);
        $page = 1;

        do {
            $response = $client->listSources(self::DESTINATIONS_PAGE_SIZE, $page, ['push']);
            $sources = $response['sources'] ?? [];
            $nbPages = $response['pagination']['nbPages'] ?? 1;

            foreach ($sources as $source) {
                if ($source['name'] === $sourceName) {
                    return $source['sourceID'];
                }
            }

            $page++;
        } while ($page <= $nbPages);

        return null;
    }

    protected function getSourceName(int $storeId, ?string $entityType): string
    {
        $pipelineName = $this->getTaskPipelineName($storeId);
        if ($entityType === null) {
            return $pipelineName;
        }
        return $pipelineName . ' - ' . $entityType;
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
        return $response['taskID'];
    }

    /**
     * Create a source and task against an existing destination (reusing any merchant transformations attached to it),
     * persist the record, and return the new task UUID.
     *
     * @throws AlreadyExistsException
     */
    protected function createTaskForExistingDestination(
        IngestionClient $client,
        int $storeId,
        string $indexName,
        array $destination,
        ?string $entityType = null
    ): string {
        $sourceId = $this->getSource($client, $storeId, $entityType);
        $taskId = $this->createTask($client, $sourceId, $destination['destinationID']);
        $origin = $this->resolveOrigin($destination, null, $storeId, $indexName, $entityType);
        $this->persistTask(
            $storeId,
            $indexName,
            $taskId,
            $sourceId,
            $destination['destinationID'],
            $destination['authenticationID'],
            $origin
        );

        $this->logger->info('Created ingestion task for existing destination ({originLabel}) for {indexName} (store {storeId})', [
            'storeId'          => $storeId,
            'indexName'        => $indexName,
            'taskId'           => $taskId,
            'sourceId'         => $sourceId,
            'destinationId'    => $destination['destinationID'],
            'authenticationId' => $destination['authenticationID'],
            'origin'           => $origin,
            'originLabel'      => self::getOriginLabel($origin),
        ]);

        return $taskId;
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
        ?string $authenticationId = null,
        int $origin = self::ORIGIN_MAGENTO
    ): void {
        $task = $this->taskFactory->create();
        $task->setData([
            'store_id'          => $storeId,
            'index_name'        => $indexName,
            'task_id'           => $taskId,
            'source_id'         => $sourceId,
            'destination_id'    => $destinationId,
            'authentication_id' => $authenticationId,
            'origin'            => $origin,
        ]);
        $this->taskResource->save($task);
    }

    /**
     * Fetch the destination for the given task, extract its authenticationID, persist the task record, and
     * return the task UUID. Requires a separate GET request because listTasks does not return destination details.
     *
     * @throws AlreadyExistsException
     */
    protected function persistDiscoveredTask(
        IngestionClient $client,
        int $storeId,
        string $indexName,
        array $task,
        ?string $entityType
    ): string {
        $destination = $client->getDestination($task['destinationID']);
        $authenticationId = $destination['authenticationID'] ?? null;
        $source = $client->getSource($task['sourceID']);
        $origin = $this->resolveOrigin($destination, $source, $storeId, $indexName, $entityType);

        $this->persistTask(
            $storeId,
            $indexName,
            $task['taskID'],
            $task['sourceID'],
            $task['destinationID'],
            $authenticationId,
            $origin
        );

        $this->logger->info(
            'Discovered ingestion pipeline ({originLabel}) for {indexName} (store {storeId})',
            [
                'storeId'          => $storeId,
                'indexName'        => $indexName,
                'taskId'           => $task['taskID'],
                'sourceId'         => $task['sourceID'],
                'destinationId'    => $task['destinationID'],
                'authenticationId' => $authenticationId,
                'origin'           => $origin,
                'originLabel'      => self::getOriginLabel($origin),
            ]
        );

        return $task['taskID'];
    }
}
