<?php

namespace Algolia\Ingestion\Observer;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Invalidates persisted ingestion task UUIDs when a watched field under the
 * Indexing Manager section is saved.
 *
 * Task UUIDs are region-scoped on the Algolia side, so a region change in
 * `algoliasearch_indexing_manager/ingestion/region` makes any cached task UUID
 * inaccessible from the new region's endpoint. The `changed_paths` event data
 * lets us skip the invalidation when only unrelated fields (`enable`,
 * `fallback_to_batch`) were touched.
 */
class IngestionConfigChangeObserver implements ObserverInterface
{
    use AffectedStoreResolverTrait;

    public const WATCHED_PATHS = [
        'algoliasearch_indexing_manager/ingestion/region',
    ];

    public function __construct(
        protected IngestionTaskServiceInterface $taskService,
        protected StoreManagerInterface         $storeManager
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->eventTouchesWatchedPaths($observer)) {
            return;
        }
        foreach ($this->resolveAffectedStoreIds($observer) as $storeId) {
            $this->taskService->invalidateByStoreId($storeId);
        }
    }

    protected function getWatchedPaths(): array
    {
        return self::WATCHED_PATHS;
    }
}
