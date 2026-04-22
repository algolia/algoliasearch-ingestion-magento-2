<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Api\SendStrategyInterface;
use Algolia\AlgoliaSearch\Exceptions\BadRequestException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Service\DirectSendStrategy;
use Algolia\AlgoliaSearch\Service\IndexNameFetcher;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Helper\IngestionConfigHelper;

class IngestionSendStrategy implements SendStrategyInterface
{
    public function __construct(
        protected IngestionConfigHelper            $configHelper,
        protected IngestionClientProviderInterface $clientProvider,
        protected IngestionTaskServiceInterface    $taskService,
        protected IndexNameFetcher                 $indexNameFetcher,
        protected DirectSendStrategy               $directSendStrategy,
        protected LoggerInterface                  $logger
    ) {}

    public function isApplicable(int $storeId): bool
    {
        return $this->configHelper->isEnabled($storeId);
    }

    public function send(IndexOptionsInterface $indexOptions, array $requests): array
    {
        try {
            $client = $this->clientProvider->getClient($indexOptions->getStoreId());
            $groups = $this->groupRequestsByAction($requests);
            $lastResponse = [];

            foreach ($groups as $action => $records) {
                $lastResponse = $this->pushActionGroupWithRetry($client, $indexOptions, $action, $records);
            }

            return $lastResponse;
        } catch (\Throwable $e) {
            return $this->handleError($e, $indexOptions, $requests);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function groupRequestsByAction(array $requests): array
    {
        $groups = [];
        foreach ($requests as $request) {
            $action = $request['action'];
            $groups[$action][] = $request['body'];
        }
        return $groups;
    }

    protected function pushActionGroupWithRetry(
        IngestionClient $client,
        IndexOptionsInterface $indexOptions,
        string $action,
        array $records
    ): array {
        try {
            return $this->pushActionGroup($client, $indexOptions, $action, $records);
        } catch (NotFoundException $e) {
            if ($indexOptions->isTemporaryIndex()) {
                throw $e; // Unexpected error - rethrow
            }

            $this->logger->warning('Ingestion pushTask 404 - invalidating stale task', [
                'storeId' => $indexOptions->getStoreId(),
                'indexName' => $indexOptions->getIndexName(),
            ]);

            $this->taskService->invalidate($indexOptions);
            return $this->pushToProductionIndex($client, $indexOptions, ['action' => $action, 'records' => $records]);
        }
    }

    protected function pushActionGroup(
        IngestionClient $client,
        IndexOptionsInterface $indexOptions,
        string $action,
        array $records
    ): array {
        $payload = ['action' => $action, 'records' => $records];

        if ($indexOptions->isTemporaryIndex()) {
            return $this->pushToTemporaryIndex($client, $indexOptions, $payload);
        }

        return $this->pushToProductionIndex($client, $indexOptions, $payload);
    }

    protected function pushToTemporaryIndex(
        IngestionClient $client,
        IndexOptionsInterface $indexOptions,
        array $payload
    ): array {
        $tempIndexName = $indexOptions->getIndexName();
        $storeId = $indexOptions->getStoreId();
        $productionIndexName = $this->indexNameFetcher->getOriginalIndexName($tempIndexName);
        $response = $client->push(
            $tempIndexName,
            $payload,
            true, // move index operations require that this be a synchronous call
            $productionIndexName
        );
        $this->logger->info(
            'Ingestion push response',
            array_merge([
                'storeId'   => $storeId,
                'indexName' => $tempIndexName,
                'action'    => $payload['action']
            ], $response)
        );
        return $response;
    }

    /**
     * @internal Experimental method - DO NOT USE
     */
    protected function pushToProductionIndexWithoutTask(
        IngestionClient $client,
        IndexOptionsInterface $indexOptions,
        array $payload
    ): array {
        $indexName = $indexOptions->getIndexName();
        $storeId = $indexOptions->getStoreId();

        $response = $client->push(
            $indexName,
            $payload
        );

        $this->logger->info(
            'Ingestion push response (no task)',
            array_merge([
                'storeId'   => $storeId,
                'indexName' => $indexName,
                'action'    => $payload['action']
            ], $response)
        );
        return $response;
    }

    protected function pushToProductionIndex(
        IngestionClient $client,
        IndexOptionsInterface $indexOptions,
        array $payload
    ): array {
        $taskId = $this->taskService->getTaskId($indexOptions);
        $response = $client->pushTask($taskId, $payload);
        $this->logger->info(
            'Ingestion pushTask response',
            array_merge([
                'taskId'    => $taskId,
                'storeId'   => $indexOptions->getStoreId(),
                'indexName' => $indexOptions->getIndexName(),
                'action'    => $payload['action']
            ], $response)
        );
        return $response;
    }

    protected function handleError(\Throwable $e, IndexOptionsInterface $indexOptions, array $requests): array
    {
        $storeId = $indexOptions->getStoreId();
        $indexName = $indexOptions->getIndexName();

        if ($this->isMultiTaskAmbiguity($e)) {
            $this->logger->warning('Ingestion push failed due to multi-task ambiguity', [
                'storeId' => $storeId,
                'indexName' => $indexName,
                'message' => $e->getMessage(),
            ]);
        } else {
            $this->logger->error('Ingestion push failed', [
                'storeId' => $storeId,
                'indexName' => $indexName,
                'exception' => $e,
            ]);
        }

        if ($this->configHelper->isFallbackEnabled($storeId)) {
            $this->logger->info('Falling back to direct batch operation', [
                'storeId' => $storeId,
                'indexName' => $indexName,
            ]);
            return $this->directSendStrategy->send($indexOptions, $requests);
        }

        throw $e;
    }

    protected function isMultiTaskAmbiguity(\Throwable $e): bool
    {
        if (!$e instanceof BadRequestException) {
            return false;
        }
        $message = strtolower($e->getMessage());
        return str_contains($message, 'multiple tasks');
    }
}
