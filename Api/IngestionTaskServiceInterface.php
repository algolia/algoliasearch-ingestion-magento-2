<?php

namespace Algolia\Ingestion\Api;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;

interface IngestionTaskServiceInterface
{
    /**
     * Return the Ingestion API task UUID for the given index options,
     * using a discover-then-reuse-then-create strategy backed by
     * in-memory and MySQL caching.
     */
    public function getTaskId(IndexOptionsInterface $indexOptions): string;

    /**
     * Invalidate the in-memory and persisted task record for the given
     * index options.
     */
    public function invalidate(IndexOptionsInterface $indexOptions): void;

    /**
     * Invalidate all cached task records for a store (e.g. on store
     * config change or credential rotation).
     */
    public function invalidateByStoreId(int $storeId): void;
}
