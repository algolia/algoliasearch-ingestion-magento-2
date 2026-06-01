<?php

namespace Algolia\Ingestion\Observer;

use Magento\Framework\Event\Observer;

/**
 * Centralizes two concerns for `admin_system_config_changed_section_*` observers:
 *  1. Filtering by `changed_paths` so observers only act when a field they care about
 *     actually changed (avoids wiping persisted task UUIDs on unrelated saves).
 *  2. Resolving the store / website / default scope into a flat list of store IDs.
 *
 * Consumers must expose a `StoreManagerInterface $storeManager` property.
 */
trait AffectedStoreResolverTrait
{
    /**
     * True if the event's `changed_paths` data contains at least one of the
     * supplied watched paths. If `changed_paths` is missing or empty, returns
     * false so the caller skips invalidation.
     *
     * @param string[] $watched fully-qualified config paths the caller cares about,
     *                          e.g. `algoliasearch_indexing_manager/ingestion/region`
     */
    protected function eventTouchesWatchedPaths(Observer $observer, array $watched): bool
    {
        $changedPaths = $observer->getEvent()->getData('changed_paths');
        if (!is_array($changedPaths) || $changedPaths === []) {
            return false;
        }

        foreach ($changedPaths as $path) {
            if (in_array($path, $watched, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return int[]
     */
    protected function resolveAffectedStoreIds(Observer $observer): array
    {
        $storeId = $observer->getEvent()->getData('store');
        $websiteId = $observer->getEvent()->getData('website');

        if ($storeId) {
            return [(int) $storeId];
        }

        $websiteIdFilter = $websiteId ? (int) $websiteId : null;
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($websiteIdFilter !== null && (int) $store->getWebsiteId() !== $websiteIdFilter) {
                continue;
            }
            $ids[] = (int) $store->getId();
        }
        return $ids;
    }
}
