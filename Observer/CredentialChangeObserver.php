<?php

namespace Algolia\Ingestion\Observer;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

class CredentialChangeObserver implements ObserverInterface
{
    public function __construct(
        protected IngestionTaskServiceInterface $taskService,
        protected StoreManagerInterface         $storeManager
    ) {}

    public function execute(Observer $observer): void
    {
        $storeId   = $observer->getEvent()->getData('store');
        $websiteId = $observer->getEvent()->getData('website');

        if ($storeId) {
            $this->taskService->invalidateByStoreId((int) $storeId);
            return;
        }

        $websiteIdFilter = $websiteId ? (int) $websiteId : null;
        foreach ($this->storeManager->getStores() as $store) {
            if ($websiteIdFilter !== null && (int) $store->getWebsiteId() !== $websiteIdFilter) {
                continue;
            }
            $this->taskService->invalidateByStoreId((int) $store->getId());
        }
    }
}
