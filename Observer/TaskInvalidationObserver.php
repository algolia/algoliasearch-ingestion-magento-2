<?php

namespace Algolia\Ingestion\Observer;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Invalidates persisted ingestion task UUIDs when a watched config path is
 * saved in an `admin_system_config_changed_section_*` event.
 *
 * Task UUIDs are scoped on the Algolia side to:
 *  - the application ID + destination index name pair
 *    (`algoliasearch_credentials/credentials/{application_id,api_key,index_prefix}`)
 *  - the ingestion region
 *    (`algoliasearch_indexing_manager/ingestion/region`)
 *
 * Changing any of those upstream-significant fields renders any cached UUID
 * stale, so we drop the cache for every store affected by the scope of the
 * config save. The set of fields to watch is supplied per section via `di.xml`
 * virtualTypes, so this single class is reused for any section whose fields
 * affect task identity. The `changed_paths` event data lets us skip the
 * invalidation when only unrelated fields in the same section were touched.
 */
class TaskInvalidationObserver implements ObserverInterface
{
    use AffectedStoreResolverTrait;

    /**
     * @param string[] $watchedPaths fully-qualified config paths that affect task UUID identity
     */
    public function __construct(
        protected IngestionTaskServiceInterface $taskService,
        protected StoreManagerInterface         $storeManager,
        protected array                         $watchedPaths = []
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->eventTouchesWatchedPaths($observer, $this->watchedPaths)) {
            return;
        }
        foreach ($this->resolveAffectedStoreIds($observer) as $storeId) {
            $this->taskService->invalidateByStore($storeId);
        }
    }
}
