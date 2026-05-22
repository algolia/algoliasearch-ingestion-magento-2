<?php

namespace Algolia\Ingestion\Observer;

use Magento\Framework\Event\Observer;

/**
 * Resolves the set of store IDs affected by an `admin_system_config_changed_section_*` event,
 * honoring the standard store / website / default scope semantics.
 *
 * Consumers must expose a `StoreManagerInterface $storeManager` property.
 */
trait AffectedStoreResolverTrait
{
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
