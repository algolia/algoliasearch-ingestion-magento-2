<?php

namespace Algolia\Ingestion\Api;

interface IngestionTaskServiceInterface
{
    /**
     * Return the Ingestion API task UUID for the given store and index,
     * using a discover-then-reuse-then-create strategy backed by
     * in-memory and MySQL caching.
     */
    public function getTaskId(int $storeId, string $indexName): string;

    /**
     * Invalidate the in-memory and persisted task record for a specific
     * store/index combination.
     */
    public function invalidate(int $storeId, string $indexName): void;

    /**
     * Invalidate all cached task records for a store (e.g. on store
     * config change or credential rotation).
     */
    public function invalidateByStoreId(int $storeId): void;
}
