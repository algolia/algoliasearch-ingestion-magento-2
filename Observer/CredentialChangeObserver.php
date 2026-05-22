<?php

namespace Algolia\Ingestion\Observer;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

class CredentialChangeObserver implements ObserverInterface
{
    use AffectedStoreResolverTrait;

    public const WATCHED_PATHS = [
        'algoliasearch_credentials/credentials/application_id',
        'algoliasearch_credentials/credentials/api_key',
        'algoliasearch_credentials/credentials/index_prefix'
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
