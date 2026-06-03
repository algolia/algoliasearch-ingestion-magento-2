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
     * Build a transaction-aware cleanup plan.
     *
     * Plan construction runs as a sequence of phases over a staged row collection.
     * Order matters: destination overrides must precede the auth override because
     * the auth shared-ref check consumes the post-override destination decisions.
     *
     * @param int[] $storeIds Empty array means "all stores".
     * @throws AlgoliaException
     */
    public function buildPlan(array $storeIds): CleanupPlan
    {
        $stage = $this->buildInitialStage($storeIds);

        $this->applySourceOverridesAcrossRows($stage);
        $this->applyDestinationOverridesAcrossRows($stage);
        $this->applyAuthOverridesAcrossRows($stage);
        $this->attachPreservedTransformationsAcrossRows($stage);

        $rows = array_map(fn(array $state) => $this->materializeRowPlan($state), $stage);

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

    // ---- Phase 0: initial stage ----

    /**
     * @return array<int, array<string, mixed>>
     * @throws AlgoliaException
     */
    protected function buildInitialStage(array $storeIds): array
    {
        $collection = $this->collectionFactory->create();
        if (!empty($storeIds)) {
            $collection->addFieldToFilter('store_id', ['in' => $storeIds]);
        }

        $stage = [];
        foreach ($collection as $task) {
            /** @var IngestionTask $task */
            $stage[] = $this->buildInitialRowState($task);
        }
        return $stage;
    }

    /**
     * @return array<string, mixed>
     * @throws AlgoliaException
     */
    protected function buildInitialRowState(IngestionTask $task): array
    {
        $storeId       = (int) $task->getData('store_id');
        $indexName     = (string) $task->getData('index_name');
        $origin        = (int) $task->getData('origin');
        $taskId        = $this->nullableString($task->getData('task_id'));
        $sourceId      = $this->nullableString($task->getData('source_id'));
        $destinationId = $this->nullableString($task->getData('destination_id'));
        $authId        = $this->nullableString($task->getData('authentication_id'));

        if ($origin === IngestionTaskService::ORIGIN_ALGOLIA) {
            return [
                'task'                       => $task,
                'client'                     => null,
                'storeId'                    => $storeId,
                'indexName'                  => $indexName,
                'origin'                     => $origin,
                'objects'                    => [
                    RowPlan::OBJECT_TASK           => ObjectPlan::preserve($taskId, 'Algolia-owned pipeline'),
                    RowPlan::OBJECT_SOURCE         => ObjectPlan::preserve($sourceId, 'Algolia-owned pipeline'),
                    RowPlan::OBJECT_DESTINATION    => ObjectPlan::preserve($destinationId, 'Algolia-owned pipeline'),
                    RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::preserve($authId, 'Algolia-owned pipeline'),
                ],
                'preservedTransformationIds' => [],
            ];
        }

        $client = $this->clientProvider->getClient($storeId);

        $objects = $origin === IngestionTaskService::ORIGIN_HYBRID
            ? $this->planForHybridRow($client, $storeId, $taskId, $sourceId, $destinationId, $authId)
            : $this->planForMagentoRow($taskId, $sourceId, $destinationId, $authId);

        return [
            'task'                       => $task,
            'client'                     => $client,
            'storeId'                    => $storeId,
            'indexName'                  => $indexName,
            'origin'                     => $origin,
            'objects'                    => $objects,
            'preservedTransformationIds' => [],
        ];
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

    // ---- Phase methods (one per object type) ----

    /**
     * @param array<int, array<string, mixed>> $stage
     */
    protected function applySourceOverridesAcrossRows(array &$stage): void
    {
        $ownTaskIds = $this->collectDeleteIds($stage, RowPlan::OBJECT_TASK);
        foreach ($stage as &$state) {
            if ($state['origin'] === IngestionTaskService::ORIGIN_ALGOLIA) {
                continue;
            }
            $this->applySourceOverride($state, $ownTaskIds);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $stage
     */
    protected function applyDestinationOverridesAcrossRows(array &$stage): void
    {
        $ownTaskIds = $this->collectDeleteIds($stage, RowPlan::OBJECT_TASK);
        foreach ($stage as &$state) {
            if ($state['origin'] === IngestionTaskService::ORIGIN_ALGOLIA) {
                continue;
            }
            $this->applyDestinationOverride($state, $ownTaskIds);
        }
    }

    /**
     * Run AFTER destination overrides so the destination-ID union reflects final decisions.
     * Without this ordering an auth could be deleted while a preserved destination still
     * references it, breaking an external task's pipeline.
     *
     * @param array<int, array<string, mixed>> $stage
     */
    protected function applyAuthOverridesAcrossRows(array &$stage): void
    {
        $ownDestIds = $this->collectDeleteIds($stage, RowPlan::OBJECT_DESTINATION);
        foreach ($stage as &$state) {
            if ($state['origin'] === IngestionTaskService::ORIGIN_ALGOLIA) {
                continue;
            }
            $this->applyAuthOverride($state, $ownDestIds);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $stage
     */
    protected function attachPreservedTransformationsAcrossRows(array &$stage): void
    {
        foreach ($stage as &$state) {
            $destPlan = $state['objects'][RowPlan::OBJECT_DESTINATION] ?? null;
            if ($destPlan instanceof ObjectPlan && $destPlan->canDelete() && $state['client'] !== null) {
                $state['preservedTransformationIds'] = $this->fetchTransformationIds($state['client'], $destPlan->id);
            }
        }
    }

    // ---- Per-row overrides ----

    /**
     * @param array<string, mixed> $state
     * @param string[] $ownTaskIds
     */
    protected function applySourceOverride(array &$state, array $ownTaskIds): void
    {
        $sourcePlan = $state['objects'][RowPlan::OBJECT_SOURCE];
        if ($sourcePlan->canDelete()
            && $this->isSourceShared($state['client'], $sourcePlan->id, $ownTaskIds)) {
            $state['objects'][RowPlan::OBJECT_SOURCE] = ObjectPlan::preserve(
                $sourcePlan->id,
                'still referenced by external task'
            );
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param string[] $ownTaskIds
     */
    protected function applyDestinationOverride(array &$state, array $ownTaskIds): void
    {
        $destPlan = $state['objects'][RowPlan::OBJECT_DESTINATION];
        if ($destPlan->canDelete()
            && $this->isDestinationShared($state['client'], $destPlan->id, $ownTaskIds)) {
            $state['objects'][RowPlan::OBJECT_DESTINATION] = ObjectPlan::preserve(
                $destPlan->id,
                'still referenced by external task'
            );
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param string[] $ownDestIds
     */
    protected function applyAuthOverride(array &$state, array $ownDestIds): void
    {
        $authPlan = $state['objects'][RowPlan::OBJECT_AUTHENTICATION];
        if ($authPlan->canDelete()
            && $this->isAuthShared($state['client'], $authPlan->id, $ownDestIds)) {
            $state['objects'][RowPlan::OBJECT_AUTHENTICATION] = ObjectPlan::preserve(
                $authPlan->id,
                'still referenced by external destination'
            );
        }
    }

    // ---- Shared-ref helpers ----

    /**
     * @param string[] $ownTaskIds
     */
    protected function isSourceShared(IngestionClient $client, string $sourceId, array $ownTaskIds): bool
    {
        $response = $this->safeFetch(fn() => $client->listTasks(
            itemsPerPage: null,
            page: null,
            action: null,
            enabled: null,
            sourceID: [$sourceId]
        ));
        return $this->hasExternalReference($response['tasks'] ?? [], 'taskID', $ownTaskIds);
    }

    /**
     * @param string[] $ownTaskIds
     */
    protected function isDestinationShared(IngestionClient $client, string $destinationId, array $ownTaskIds): bool
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
        return $this->hasExternalReference($response['tasks'] ?? [], 'taskID', $ownTaskIds);
    }

    /**
     * @param string[] $ownDestIds
     */
    protected function isAuthShared(IngestionClient $client, string $authId, array $ownDestIds): bool
    {
        $response = $this->safeFetch(fn() => $client->listDestinations(
            itemsPerPage: null,
            page: null,
            type: null,
            authenticationID: [$authId]
        ));
        return $this->hasExternalReference($response['destinations'] ?? [], 'destinationID', $ownDestIds);
    }

    /**
     * True when at least one record references an ID outside the "ours" set.
     *
     * @param array<int, array<string, mixed>> $records
     * @param string[] $ownIds
     */
    protected function hasExternalReference(array $records, string $idField, array $ownIds): bool
    {
        foreach ($records as $record) {
            $id = $record[$idField] ?? null;
            if ($id !== null && !in_array($id, $ownIds, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collect unique non-null IDs from the stage for objects whose plan is currently DELETE.
     *
     * @param array<int, array<string, mixed>> $stage
     * @return string[]
     */
    protected function collectDeleteIds(array $stage, string $objectType): array
    {
        $ids = [];
        foreach ($stage as $state) {
            $plan = $state['objects'][$objectType] ?? null;
            if ($plan instanceof ObjectPlan && $plan->canDelete()) {
                $ids[] = $plan->id;
            }
        }
        return array_values(array_unique($ids));
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

    // ---- Materialize and execute ----

    /**
     * @param array<string, mixed> $state
     */
    protected function materializeRowPlan(array $state): RowPlan
    {
        return new RowPlan(
            $state['task'],
            $state['storeId'],
            $state['indexName'],
            $state['origin'],
            IngestionTaskService::getOriginLabel($state['origin']),
            $state['objects'],
            $state['preservedTransformationIds']
        );
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
