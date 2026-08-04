<?php

namespace Algolia\Ingestion\Test\Integration\Indexing;

use Algolia\AlgoliaSearch\Api\Processor\BatchQueueProcessorInterface;
use Algolia\AlgoliaSearch\Test\Integration\Indexing\IndexingTestCase;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Console\Command\Ingestion\IngestionInitCommand;
use Algolia\Ingestion\Service\IngestionCleanupService;
use Algolia\Ingestion\Service\IngestionSendStrategy;

class IngestionIndexingTestCase extends IndexingTestCase
{
    protected ?IngestionTaskServiceInterface $taskService = null;
    protected ?IngestionCleanupService $cleanupService = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfig('algoliasearch_indexing_manager/ingestion/enable', 1);
        $this->setConfig(
            'algoliasearch_indexing_manager/ingestion/region',
            getenv('ALGOLIA_REGION') ?? 'us'
        );

        // Wait for all actions to be finished
        IngestionSendStrategy::setSynchronousMode(true);

        $this->taskService = $this->objectManager->get(IngestionTaskServiceInterface::class);
        $this->cleanupService = $this->objectManager->get(IngestionCleanupService::class);
    }

    protected function tearDown(): void
    {
        $plan = $this->cleanupService->buildPlan([1]);
        $this->cleanupService->execute($plan);

        parent::tearDown();
    }

    protected function initEntityTask(string $entity, ?int $storeId = 1): void
    {
        $indexOptions = $this->indexOptionsBuilder->buildWithComputedIndex($entity, $storeId);
        $this->taskService->getTaskId($indexOptions);
    }

    protected function initAllEntityTasks(?int $storeId = 1): void
    {
        foreach (IngestionInitCommand::ENTITY_SUFFIXES as $entity)
        {
            $this->initEntityTask($entity, $storeId);
        }
    }

    protected function processTest(
        BatchQueueProcessorInterface $batchQueueProcessor,
                                     $indexSuffix,
                                     $expectedNbHits
    ) {
        $indexOptions = $this->getIndexOptions($indexSuffix);

        $this->algoliaConnector->clearIndex($indexOptions);
        $batchQueueProcessor->processBatch(1);

        $this->assertNumberofHits($indexSuffix, $expectedNbHits);
    }
}
