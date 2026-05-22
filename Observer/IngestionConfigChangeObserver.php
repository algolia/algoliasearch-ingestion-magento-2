<?php

namespace Algolia\Ingestion\Observer;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Invalidates persisted ingestion task UUIDs when the Indexing Manager section is saved.
 *
 * Task UUIDs are region-scoped on the Algolia side, so a region change in
 * `algoliasearch_indexing_manager/ingestion/region` makes any cached task UUID
 * inaccessible from the new region's endpoint. Section-level events don't expose
 * which field changed, so we invalidate on any save under the section - other
 * fields will just trigger a harmless rediscovery on next push.
 */
class IngestionConfigChangeObserver implements ObserverInterface
{
    use AffectedStoreResolverTrait;

    public function __construct(
        protected IngestionTaskServiceInterface $taskService,
        protected StoreManagerInterface         $storeManager
    ) {}

    public function execute(Observer $observer): void
    {
        foreach ($this->resolveAffectedStoreIds($observer) as $storeId) {
            $this->taskService->invalidateByStoreId($storeId);
        }
    }
}
