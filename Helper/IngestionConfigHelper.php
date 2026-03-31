<?php

namespace Algolia\Ingestion\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class IngestionConfigHelper
{
    public const INGESTION_ENABLED = 'algoliasearch_indexing_manager/ingestion/enable';
    public const INGESTION_REGION = 'algoliasearch_indexing_manager/ingestion/region';
    public const INGESTION_FALLBACK_ENABLED = 'algoliasearch_indexing_manager/ingestion/fallback_to_batch';

    public function __construct(
        protected ScopeConfigInterface $configInterface,
    ) {}

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->configInterface->isSetFlag(self::INGESTION_ENABLED, ScopeInterface::SCOPE_STORES, $storeId);
    }

    public function getRegion(?int $storeId = null): string
    {
        return $this->configInterface->getValue(self::INGESTION_REGION, ScopeInterface::SCOPE_STORES, $storeId);
    }

    public function isFallbackEnabled(?int $storeId = null): bool
    {
        return $this->configInterface->isSetFlag(
            self::INGESTION_FALLBACK_ENABLED,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
    }
}
