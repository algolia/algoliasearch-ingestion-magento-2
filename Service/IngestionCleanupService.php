<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Algolia\Ingestion\Model\Cleanup\Plan\CleanupPlan;
use Algolia\Ingestion\Model\Cleanup\Plan\ObjectPlan;
use Algolia\Ingestion\Model\Cleanup\Plan\RowPlan;
use Algolia\Ingestion\Model\Cleanup\Result\CleanupResult;
use Algolia\Ingestion\Model\Cleanup\Result\RowResult;

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
     * Execute the plan one object type at a time across all rows in a store, in
     * dependency order: every task delete first, then every source, then every
     * destination, then every authentication.
     *
     * The alternative — processing one row at a time (delete this row's task, source,
     * destination, auth, then move to the next row) — fails for shared resources.
     * If three rows share the same authentication, the first row's auth-delete runs
     * while the other two rows' destinations still reference it; Algolia rejects the
     * call. Sequencing all destinations before any auth-delete avoids this and lets
     * each unique (type, id) collapse to a single API call.
     *
     * @throws AlgoliaException
     */
    public function execute(CleanupPlan $plan): CleanupResult
    {
        $rowsByStore = [];
        foreach ($plan->rows as $row) {
            $rowsByStore[$row->storeId][] = $row;
        }

        // outcomes[storeId][type][id] = ['status' => 'success'|'failure', 'message' => ?string]
        $outcomes = [];
        foreach ($rowsByStore as $storeId => $rows) {
            $outcomes[$storeId] = $this->executeStoreBatch($storeId, $rows);
        }

        $results = [];
        foreach ($plan->rows as $row) {
            $results[] = $this->buildRowResult($row, $outcomes[$row->storeId] ?? []);
        }
        return new CleanupResult($results);
    }

    /**
     * Run distinct deletes for this store in dependency order: task -> source -> destination -> auth.
     *
     * @param RowPlan[] $rows
     * @return array<string, array<string, array{status: string, message: ?string}>>
     */
    protected function executeStoreBatch(int $storeId, array $rows): array
    {
        $outcomes = [];
        foreach (RowPlan::OBJECT_TYPES as $type) {
            foreach ($this->collectDeletesForType($rows, $type) as $id) {
                $outcomes[$type][$id] = $this->attemptDelete($storeId, $type, $id);
            }
        }
        return $outcomes;
    }

    /**
     * @param RowPlan[] $rows
     * @return string[]
     */
    protected function collectDeletesForType(array $rows, string $type): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $object = $row->getObject($type);
            if ($object->canDelete()) {
                $ids[$object->id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * @return array{status: string, message: ?string}
     */
    protected function attemptDelete(int $storeId, string $type, string $id): array
    {
        try {
            $this->deleteRemoteObject($storeId, $type, $id);
            return ['status' => RowResult::STATUS_SUCCESS, 'message' => null];
        } catch (NotFoundException) {
            return ['status' => RowResult::STATUS_SUCCESS, 'message' => null];
        } catch (\Throwable $e) {
            $this->logger->error('Ingestion cleanup failed on {type} delete: {message}', [
                'type'    => $type,
                'message' => $e->getMessage(),
                'storeId' => $storeId,
                'objectId' => $id,
            ]);
            return ['status' => RowResult::STATUS_FAILED, 'message' => $e->getMessage()];
        }
    }

    /**
     * Compose a per-row result by looking up the outcome of each of this row's deletes
     * in the store-wide outcome map. The row is failed if ANY of its deletes failed;
     * otherwise the local row is invalidated and the row reports success.
     *
     * @param array<string, array<string, array{status: string, message: ?string}>> $storeOutcomes
     */
    protected function buildRowResult(RowPlan $row, array $storeOutcomes): RowResult
    {
        $deleted = 0;
        $preserved = count($row->preserves());
        $firstFailureType = null;
        $firstFailureMessage = null;

        foreach (RowPlan::OBJECT_TYPES as $type) {
            $object = $row->getObject($type);
            if (!$object->canDelete()) {
                continue;
            }
            $outcome = $storeOutcomes[$type][$object->id] ?? null;
            if ($outcome === null) {
                continue;
            }
            if ($outcome['status'] === RowResult::STATUS_SUCCESS) {
                $deleted++;
            } elseif ($firstFailureType === null) {
                $firstFailureType = $type;
                $firstFailureMessage = $outcome['message'];
            }
        }

        if ($firstFailureType !== null) {
            return new RowResult(
                $row,
                RowResult::STATUS_FAILED,
                $deleted,
                $preserved,
                $firstFailureType,
                $firstFailureMessage
            );
        }

        try {
            $this->taskService->invalidate($row->task);
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

        $objects = $origin === IngestionTaskService::ORIGIN_HYBRID
            ? $this->planForHybridRow($storeId, $taskId, $sourceId, $destinationId, $authId)
            : $this->planForMagentoRow($taskId, $sourceId, $destinationId, $authId);

        return [
            'task'                       => $task,
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
    protected function planForMagentoRow(
        ?string $taskId,
        ?string $sourceId,
        ?string $destinationId,
        ?string $authId
    ): array {
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
        int $storeId,
        ?string $taskId,
        ?string $sourceId,
        ?string $destinationId,
        ?string $authId
    ): array {
        $client = $this->clientProvider->getClient($storeId);
        $source = $sourceId !== null ? $this->safeFetch(fn() => $client->getSource($sourceId)) : null;
        $destination = $destinationId !== null
            ? $this->safeFetch(fn() => $client->getDestination($destinationId))
            : null;
        $pipelinePrefix = $this->taskService->getTaskPipelineName($storeId);

        $sourceIsMerchant = $source !== null && !str_starts_with((string)($source['name'] ?? ''), $pipelinePrefix);
        $destinationIsMerchant = $destination !== null
            && !str_starts_with((string)($destination['name'] ?? ''), $pipelinePrefix);

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
            if ($destPlan instanceof ObjectPlan && $destPlan->canDelete()) {
                $state['preservedTransformationIds'] = $this->fetchTransformationIds($state['storeId'], $destPlan->id);
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
            && $this->isSourceShared($state['storeId'], $sourcePlan->id, $ownTaskIds)) {
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
            && $this->isDestinationShared($state['storeId'], $destPlan->id, $ownTaskIds)) {
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
            && $this->isAuthShared($state['storeId'], $authPlan->id, $ownDestIds)) {
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
    protected function isSourceShared(int $storeId, string $sourceId, array $ownTaskIds): bool
    {
        $client = $this->clientProvider->getClient($storeId);
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
    protected function isDestinationShared(int $storeId, string $destinationId, array $ownTaskIds): bool
    {
        $client = $this->clientProvider->getClient($storeId);
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
    protected function isAuthShared(int $storeId, string $authId, array $ownDestIds): bool
    {
        $client = $this->clientProvider->getClient($storeId);
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
    protected function fetchTransformationIds(int $storeId, string $destinationId): array
    {
        $client = $this->clientProvider->getClient($storeId);
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

    // ---- Materialize ----

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

    protected function deleteRemoteObject(int $storeId, string $type, string $id): void
    {
        $client = $this->clientProvider->getClient($storeId);
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
