<?php

namespace Algolia\Ingestion\Api;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\Ingestion\Model\IngestionTask;

interface IngestionTaskServiceInterface
{
    /**
     * Return the Ingestion API task UUID for the given index options,
     * using a discover-then-reuse-then-create strategy backed by
     * in-memory and MySQL caching.
     */
    public function getTaskId(IndexOptionsInterface $indexOptions): string;

    /**
     * Invalidate a single in-memory and persisted task record. Use
     * this variant when the caller already holds the IngestionTask
     * (e.g. resource cleanup) and wants to wipe one row without
     * enumerating the store.
     */
    public function invalidate(IngestionTask $task): void;

    /**
     * Invalidate the in-memory and persisted task record matching the
     * given index options.
     */
    public function invalidateByIndex(IndexOptionsInterface $indexOptions): void;

    /**
     * Invalidate every in-memory and persisted task record for a
     * store (e.g. on store config change or credential rotation).
     */
    public function invalidateByStore(int $storeId): void;
}
