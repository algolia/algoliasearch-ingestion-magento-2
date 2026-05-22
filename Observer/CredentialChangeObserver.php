<?php

namespace Algolia\Ingestion\Observer;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Invalidates persisted ingestion task UUIDs when a watched field under the
 * Algolia Credentials section is saved.
 *
 * Task UUIDs are scoped to the (application ID + destination index name) pair on
 * the Algolia side, so changing `application_id` (different organization),
 * `api_key` (admin key rotation against a different app), or `index_prefix`
 * (destination index names change) renders any cached UUID stale. The
 * `changed_paths` event data lets us skip the invalidation when only unrelated
 * fields (`search_only_api_key`, `debug`, cookie configuration) were touched.
 */
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
        if (!$this->eventTouchesWatchedPaths($observer, self::WATCHED_PATHS)) {
            return;
        }
        foreach ($this->resolveAffectedStoreIds($observer) as $storeId) {
            $this->taskService->invalidateByStoreId($storeId);
        }
    }
}
