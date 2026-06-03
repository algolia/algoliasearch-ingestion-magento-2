<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Algolia\Ingestion\Model\Cleanup\CleanupPlan;
use Algolia\Ingestion\Model\Cleanup\CleanupResult;
use Algolia\Ingestion\Model\Cleanup\ObjectPlan;
use Algolia\Ingestion\Model\Cleanup\RowPlan;
use Algolia\Ingestion\Model\Cleanup\RowResult;

class IngestionCleanupService
{
    public function __construct(
        protected IngestionClientProviderInterface $clientProvider,
        protected CollectionFactory                $collectionFactory,
        protected IngestionTaskService             $taskService,
        protected LoggerInterface                  $logger
    ) {}

    /**
     * @param int[] $storeIds Empty array means "all stores".
     * @throws AlgoliaException
     */
    public function buildPlan(array $storeIds): CleanupPlan
    {
        $collection = $this->collectionFactory->create();
        if (!empty($storeIds)) {
            $collection->addFieldToFilter('store_id', ['in' => $storeIds]);
        }

        $rows = [];
        foreach ($collection as $task) {
            /** @var IngestionTask $task */
            $rows[] = $this->buildRowPlan($task);
        }

        return new CleanupPlan($rows, $storeIds, new \DateTimeImmutable());
    }

    /**
     * @throws AlgoliaException
     */
    public function execute(CleanupPlan $plan): CleanupResult
    {
        $results = [];
        foreach ($plan->rows as $row) {
            $results[] = $this->executeRow($row);
        }
        return new CleanupResult($results);
    }

    /**
     * @throws AlgoliaException
     */
    protected function buildRowPlan(IngestionTask $task): RowPlan
    {
        $storeId       = (int) $task->getData('store_id');
        $indexName     = (string) $task->getData('index_name');
        $origin        = (int) $task->getData('origin');
        $taskId        = $this->nullableString($task->getData('task_id'));
        $sourceId      = $this->nullableString($task->getData('source_id'));
        $destinationId = $this->nullableString($task->getData('destination_id'));
        $authId        = $this->nullableString($task->getData('authentication_id'));

        if ($origin === IngestionTaskService::ORIGIN_ALGOLIA) {
            $objects = [
                RowPlan::OBJECT_TASK           => ObjectPlan::preserve($taskId, 'Algolia-owned pipeline'),
                RowPlan::OBJECT_SOURCE         => ObjectPlan::preserve($sourceId, 'Algolia-owned pipeline'),
                RowPlan::OBJECT_DESTINATION    => ObjectPlan::preserve($destinationId, 'Algolia-owned pipeline'),
                RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::preserve($authId, 'Algolia-owned pipeline'),
            ];
            return new RowPlan(
                $task,
                $storeId,
                $indexName,
                $origin,
                IngestionTaskService::getOriginLabel($origin),
                $objects
            );
        }

        $client = $this->clientProvider->getClient($storeId);

        $objects = $origin === IngestionTaskService::ORIGIN_HYBRID
            ? $this->planForHybridRow($client, $storeId, $taskId, $sourceId, $destinationId, $authId)
            : $this->planForMagentoRow($taskId, $sourceId, $destinationId, $authId);

        $objects = $this->applySharedRefOverrides($client, $objects, $taskId, $destinationId);

        $preservedTransformations = [];
        $destinationPlan = $objects[RowPlan::OBJECT_DESTINATION];
        if ($destinationPlan->canDelete()) {
            $preservedTransformations = $this->fetchTransformationIds($client, $destinationPlan->id);
        }

        return new RowPlan(
            $task,
            $storeId,
            $indexName,
            $origin,
            IngestionTaskService::getOriginLabel($origin),
            $objects,
            $preservedTransformations
        );
    }

    /**
     * @return array<string, ObjectPlan>
     */
    protected function planForMagentoRow(?string $taskId, ?string $sourceId, ?string $destinationId, ?string $authId): array
    {
        return [
            RowPlan::OBJECT_TASK           => $this->initDelete($taskId),
            RowPlan::OBJECT_SOURCE         => $this->initDelete($sourceId),
            RowPlan::OBJECT_DESTINATION    => $this->initDelete($destinationId),
            RowPlan::OBJECT_AUTHENTICATION => $this->initDelete($authId),
        ];
    }

    /**
     * Hybrid rows have one Magento-owned side and one merchant-owned side. We confirm merchant ownership
     * via name lookup; anything not confirmed as merchant-owned (including 404'd fetches) is marked for
     * delete and will no-op safely at execute time.
     *
     * @return array<string, ObjectPlan>
     */
    protected function planForHybridRow(
        IngestionClient $client,
        int $storeId,
        ?string $taskId,
        ?string $sourceId,
        ?string $destinationId,
        ?string $authId
    ): array {
        $source        = $sourceId !== null ? $this->safeFetch(fn() => $client->getSource($sourceId)) : null;
        $destination   = $destinationId !== null ? $this->safeFetch(fn() => $client->getDestination($destinationId)) : null;
        $pipelinePrefix = $this->taskService->getTaskPipelineName($storeId);

        $sourceIsMerchant      = $source !== null && !str_starts_with((string) ($source['name'] ?? ''), $pipelinePrefix);
        $destinationIsMerchant = $destination !== null && !str_starts_with((string) ($destination['name'] ?? ''), $pipelinePrefix);

        $objects = [
            RowPlan::OBJECT_TASK => $this->initDelete($taskId),
        ];

        if ($sourceIsMerchant) {
            $objects[RowPlan::OBJECT_SOURCE] = ObjectPlan::preserve($sourceId, 'merchant-owned, not Magento-named');
        } else {
            $objects[RowPlan::OBJECT_SOURCE] = $this->initDelete($sourceId);
        }

        if ($destinationIsMerchant) {
            $objects[RowPlan::OBJECT_DESTINATION]    = ObjectPlan::preserve($destinationId, 'merchant-owned, not Magento-named');
            $objects[RowPlan::OBJECT_AUTHENTICATION] = ObjectPlan::preserve($authId, 'tied to preserved destination');
        } else {
            $objects[RowPlan::OBJECT_DESTINATION]    = $this->initDelete($destinationId);
            $objects[RowPlan::OBJECT_AUTHENTICATION] = $this->initDelete($authId);
        }

        return $objects;
    }

    protected function initDelete(?string $id): ObjectPlan
    {
        return $id !== null
            ? ObjectPlan::delete($id)
            : ObjectPlan::preserve(null, 'not recorded locally');
    }

    /**
     * Demote any DELETE that is still referenced by another task/destination to PRESERVE. Shared
     * references only matter when we are about to delete the object; PRESERVE plans are left alone.
     *
     * @param array<string, ObjectPlan> $objects
     * @return array<string, ObjectPlan>
     */
    protected function applySharedRefOverrides(
        IngestionClient $client,
        array $objects,
        ?string $ownTaskId,
        ?string $ownDestinationId
    ): array {
        $sourcePlan = $objects[RowPlan::OBJECT_SOURCE];
        if ($sourcePlan->canDelete()
            && $this->isSourceShared($client, $sourcePlan->id, $ownTaskId)) {
            $objects[RowPlan::OBJECT_SOURCE] = ObjectPlan::preserve(
                $sourcePlan->id,
                'still referenced by external task'
            );
        }

        $destinationPlan = $objects[RowPlan::OBJECT_DESTINATION];
        if ($destinationPlan->canDelete()
            && $this->isDestinationShared($client, $destinationPlan->id, $ownTaskId)) {
            $objects[RowPlan::OBJECT_DESTINATION] = ObjectPlan::preserve(
                $destinationPlan->id,
                'still referenced by external task'
            );
        }

        $authPlan = $objects[RowPlan::OBJECT_AUTHENTICATION];
        if ($authPlan->canDelete()
            && $this->isAuthShared($client, $authPlan->id, $ownDestinationId)) {
            $objects[RowPlan::OBJECT_AUTHENTICATION] = ObjectPlan::preserve(
                $authPlan->id,
                'still referenced by external destination'
            );
        }

        return $objects;
    }

    protected function isSourceShared(IngestionClient $client, string $sourceId, ?string $ownTaskId): bool
    {
        $response = $this->safeFetch(fn() => $client->listTasks(
            itemsPerPage: null,
            page: null,
            action: null,
            enabled: null,
            sourceID: [$sourceId]
        ));
        return $this->hasExternalReference($response['tasks'] ?? [], 'taskID', $ownTaskId);
    }

    protected function isDestinationShared(IngestionClient $client, string $destinationId, ?string $ownTaskId): bool
    {
        $response = $this->safeFetch(fn() => $client->listTasks(
            itemsPerPage: null,
            page: null,
            action: null,
            enabled: null,
            sourceID: null,
            sourceType: null,
            destinationID: [$destinationId]
        ));
        return $this->hasExternalReference($response['tasks'] ?? [], 'taskID', $ownTaskId);
    }

    protected function isAuthShared(IngestionClient $client, string $authId, ?string $ownDestinationId): bool
    {
        $response = $this->safeFetch(fn() => $client->listDestinations(
            itemsPerPage: null,
            page: null,
            type: null,
            authenticationID: [$authId]
        ));
        return $this->hasExternalReference($response['destinations'] ?? [], 'destinationID', $ownDestinationId);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    protected function hasExternalReference(array $records, string $idField, ?string $ownId): bool
    {
        foreach ($records as $record) {
            if (($record[$idField] ?? null) !== $ownId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    protected function fetchTransformationIds(IngestionClient $client, string $destinationId): array
    {
        $destination = $this->safeFetch(fn() => $client->getDestination($destinationId));
        if ($destination === null) {
            return [];
        }
        $ids = $destination['transformationIDs'] ?? [];
        return array_values(array_filter(array_map('strval', $ids), fn($id) => $id !== ''));
    }

    /**
     * Run a readonly API call. 404 yields null; other AlgoliaExceptions are rethrown so plan-building
     * fails fast on misconfiguration (e.g. bad credentials).
     *
     * @template T
     * @param callable():T $fn
     * @return T|array{}|null
     */
    protected function safeFetch(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * @throws AlgoliaException
     */
    protected function executeRow(RowPlan $row): RowResult
    {
        $client = $this->clientProvider->getClient($row->storeId);
        $deleted = 0;
        $preserved = count($row->preserves());

        foreach (RowPlan::OBJECT_TYPES as $type) {
            $object = $row->getObject($type);
            if (!$object->canDelete()) {
                continue;
            }

            try {
                $this->deleteRemoteObject($client, $type, $object->id);
                $deleted++;
            } catch (NotFoundException) {
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->error('Ingestion cleanup failed on {type} delete: {message}', [
                    'type'      => $type,
                    'message'   => $e->getMessage(),
                    'storeId'   => $row->storeId,
                    'indexName' => $row->indexName,
                ]);
                return new RowResult(
                    $row,
                    RowResult::STATUS_FAILED,
                    $deleted,
                    $preserved,
                    $type,
                    $e->getMessage()
                );
            }
        }

        try {
            $this->taskService->invalidateRow($row->task);
        } catch (\Throwable $e) {
            $this->logger->error('Ingestion cleanup local invalidation failed: {message}', [
                'message'   => $e->getMessage(),
                'storeId'   => $row->storeId,
                'indexName' => $row->indexName,
            ]);
            return new RowResult(
                $row,
                RowResult::STATUS_FAILED,
                $deleted,
                $preserved,
                'local-row',
                $e->getMessage()
            );
        }

        return new RowResult($row, RowResult::STATUS_SUCCESS, $deleted, $preserved);
    }

    protected function deleteRemoteObject(IngestionClient $client, string $type, string $id): void
    {
        match ($type) {
            RowPlan::OBJECT_TASK           => $client->deleteTask($id),
            RowPlan::OBJECT_SOURCE         => $client->deleteSource($id),
            RowPlan::OBJECT_DESTINATION    => $client->deleteDestination($id),
            RowPlan::OBJECT_AUTHENTICATION => $client->deleteAuthentication($id),
        };
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }
}
