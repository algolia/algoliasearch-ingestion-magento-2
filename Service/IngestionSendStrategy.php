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
            $indexName = $indexOptions->getIndexName();

            if ($this->indexNameFetcher->isTempIndex($indexName)) {
                throw $e;
            }

            $storeId = $indexOptions->getStoreId();
            $this->logger->warning('Ingestion pushTask 404 - invalidating stale task', [
                'storeId' => $storeId,
                'indexName' => $indexName,
            ]);

            $this->taskService->invalidate($storeId, $indexName);
            $taskId = $this->taskService->getTaskId($storeId, $indexName);
            $response = $this->normalizePushResponse($client->pushTask($taskId, ['action' => $action, 'records' => $records]));
            $this->logger->info('Ingestion pushTask response', array_merge(['storeId' => $storeId, 'indexName' => $indexName], $response));
            return $response;
        }
    }

    protected function pushActionGroup(
        IngestionClient $client,
        IndexOptionsInterface $indexOptions,
        string $action,
        array $records
    ): array {
        $payload = ['action' => $action, 'records' => $records];
        $indexName = $indexOptions->getIndexName();
        $storeId = $indexOptions->getStoreId();

        if ($this->indexNameFetcher->isTempIndex($indexName)) {
            $productionIndexName = substr($indexName, 0, -strlen(IndexNameFetcher::INDEX_TEMP_SUFFIX));
            $response = $this->normalizePushResponse($client->push($indexName, $payload, null, $productionIndexName));
            $this->logger->info(
                'Ingestion push response',
                array_merge([
                    'storeId'   => $storeId,
                    'indexName' => $indexName,
                    'action'    => $payload['action']
                ], $response)
            );
            return $response;
        }

        $taskId = $this->taskService->getTaskId($storeId, $indexName);
        $response = $this->normalizePushResponse($client->pushTask($taskId, $payload));
        $this->logger->info(
            'Ingestion pushTask response',
            array_merge([
                'taskId'    => $taskId,
                'storeId'   => $storeId,
                'indexName' => $indexName,
                'action'    => $payload['action']
            ], $response)
        );
        return $response;
    }

    /**
     * @param array<string, mixed>|object $response
     * @return array{runID: string|null, eventID: string|null, message: string|null, createdAt: string|null}
     */
    protected function normalizePushResponse($response): array
    {
        if (is_array($response)) {
            return [
                'runID'     => $response['runID'] ?? null,
                'eventID'   => $response['eventID'] ?? null,
                'message'   => $response['message'] ?? null,
                'createdAt' => $response['createdAt'] ?? null,
            ];
        }

        return [
            'runID'     => $response->getRunID(),
            'eventID'   => $response->getEventID(),
            'message'   => $response->getMessage(),
            'createdAt' => $response->getCreatedAt(),
        ];
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
        return str_contains($message, 'task')
            && (str_contains($message, 'zero') || str_contains($message, 'many'));
    }
}
