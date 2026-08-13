<?php

namespace Algolia\Ingestion\Test\Integration\Indexing;

use Algolia\AlgoliaSearch\Api\Processor\BatchQueueProcessorInterface;
use Algolia\AlgoliaSearch\Test\Integration\Indexing\IndexingTestCase;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Console\Command\Ingestion\IngestionInitCommand;
use Algolia\Ingestion\Service\IngestionCleanupService;
use Algolia\Ingestion\Service\IngestionClientProvider;
use Algolia\Ingestion\Service\IngestionSendStrategy;

class IngestionIndexingTestCase extends IndexingTestCase
{
    protected ?IngestionTaskServiceInterface $taskService = null;
    protected ?IngestionCleanupService $cleanupService = null;
    protected ?IngestionClientProvider $clientProvider = null;

    protected array $managedTransformations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfig('algoliasearch_indexing_manager/ingestion/enable', 1);

        $env = getenv('ALGOLIA_REGION') !== false && in_array(getenv('ALGOLIA_REGION'), ['eu', 'us']) ?
            getenv('ALGOLIA_REGION') :
            'us';

        $this->setConfig(
            'algoliasearch_indexing_manager/ingestion/region',
            $env
        );

        // Wait for all actions to be finished
        IngestionSendStrategy::setSynchronousMode(true);

        $this->taskService = $this->objectManager->get(IngestionTaskServiceInterface::class);
        $this->cleanupService = $this->objectManager->get(IngestionCleanupService::class);
        $this->clientProvider = $this->objectManager->get(IngestionClientProvider::class);
    }

    protected function tearDown(): void
    {
        $plan = $this->cleanupService->buildPlan([1]);
        $this->cleanupService->execute($plan);

        foreach ($this->managedTransformations as $transformation) {
            $this->clientProvider->getClient(1)->deleteTransformation($transformation);
        }

        IngestionSendStrategy::setSynchronousMode(null);

        parent::tearDown();
    }

    protected function initEntityTask(string $entity, ?int $storeId = 1): string
    {
        $indexOptions = $this->getIndexOptions($entity, $storeId);
        return $this->taskService->getTaskId($indexOptions);
    }

    protected function initAllEntityTasks(?int $storeId = 1): void
    {
        foreach (IngestionInitCommand::ENTITY_SUFFIXES as $entity) {
            $this->initEntityTask($entity, $storeId);
        }
    }

    protected function applyTransformation(string $taskID, ?string $code = null): void
    {
        $client = $this->clientProvider->getClient(1);

        if ($code === null) {
            $code = 'async function transform(record, helper) {
  record[\'name\'] += \' (transformed)\';
  return record;
  }';
        }

        $transformation = $client->createTransformation([
            'code' => $code,
            'name' => "transformation for $taskID"
        ]);

        $taskData = $client->getTask($taskID);
        $destinationId = $taskData['destinationID'];

        $client->updateDestination(
            $destinationId,
            ['transformationIDs' => [$transformation['transformationID']]]
        );

        $this->managedTransformations[] = $transformation['transformationID'];
    }

    protected function processTest(
        BatchQueueProcessorInterface $batchQueueProcessor,
                                     $indexSuffix,
                                     $expectedNbHits
    ) {
        $indexOptions = $this->getIndexOptions($indexSuffix);

        $this->algoliaConnector->clearIndex($indexOptions);
        $this->algoliaConnector->waitLastTask(1);

        $batchQueueProcessor->processBatch(1);
        $this->algoliaConnector->waitLastTask(1);

        $this->assertNumberofHits($indexSuffix, $expectedNbHits);
    }

    protected function assertNumberofHits($indexSuffix, $expectedNbHits)
    {
        $resultsDefault = $this->fetchRecords($indexSuffix);
        $nbHits = $resultsDefault['results'][0]['nbHits'];
        $this->assertEquals($expectedNbHits, $nbHits);
    }

    protected function assertTransformationIsApplied(
        string $indexSuffix,
        ?string $attribute = 'name',
        ?string $needle = '(transformed)',
    ): void
    {
        $resultsDefault = $this->fetchRecords($indexSuffix);
        $nbHits = $resultsDefault['results'][0]['nbHits'];

        if ($nbHits > 0) {
            $record = $resultsDefault['results'][0]['hits'][0];
            $this->assertStringContainsString($needle, $record[$attribute]);
        }
    }

    protected function fetchRecords(string $indexSuffix): array
    {
        $indexOptions = $this->getIndexOptions($indexSuffix);

        $searchQuery = $this->searchQueryFactory->create([
            'indexOptions' => $indexOptions,
            'query' => '',
            'params' => [],
        ]);
        return $this->algoliaConnector->query($searchQuery);
    }


}
