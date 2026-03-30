<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\IngestionTaskFactory;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;

class IngestionTaskService implements IngestionTaskServiceInterface
{
    /** @var array<int, array<string, string>> */
    private array $cache = [];

    public function __construct(
        private readonly IngestionClientProviderInterface $clientProvider,
        private readonly IngestionConfigHelper $configHelper,
        private readonly IngestionTaskFactory $taskFactory,
        private readonly IngestionTaskResource $taskResource,
        private readonly CollectionFactory $collectionFactory
    ) {}

    public function getTaskId(int $storeId, string $indexName): string
    {
        throw new \LogicException('Not implemented');
    }

    public function invalidate(int $storeId, string $indexName): void
    {
        throw new \LogicException('Not implemented');
    }

    public function invalidateByStoreId(int $storeId): void
    {
        throw new \LogicException('Not implemented');
    }

    private function loadFromCache(int $storeId, string $indexName): ?string
    {
        throw new \LogicException('Not implemented');
    }

    private function storeInCache(int $storeId, string $indexName, string $taskId): void
    {
        throw new \LogicException('Not implemented');
    }

    private function loadFromDatabase(int $storeId, string $indexName): ?IngestionTask
    {
        throw new \LogicException('Not implemented');
    }

    /**
     * Verify the task still exists in the Ingestion API.
     * Returns false on NotFoundException so the caller can recover.
     */
    private function verifyTaskExists(IngestionClient $client, string $taskId): bool
    {
        throw new \LogicException('Not implemented');
    }

    /**
     * Lazily paginate listTasks() and exit early when a task matching
     * the given store and index is found. Returns the task UUID or null.
     */
    private function discoverExistingTask(IngestionClient $client, int $storeId, string $indexName): ?string
    {
        throw new \LogicException('Not implemented');
    }

    /**
     * Create a full push pipeline (source + destination + task) for the
     * given store and index. Returns the new task UUID.
     */
    private function createFullPipeline(IngestionClient $client, int $storeId, string $indexName): string
    {
        throw new \LogicException('Not implemented');
    }

    private function persistTask(
        int $storeId,
        string $indexName,
        string $taskId,
        ?string $sourceId,
        ?string $destinationId
    ): void {
        throw new \LogicException('Not implemented');
    }
}
