<?php

namespace Algolia\Ingestion\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Tests\NamingConvention\true\string;

class IngestionConfigHelper
{
    public const INGESTION_ENABLED = 'algoliasearch_ingestion/general/enable';
    public const INGESTION_REGION = 'algoliasearch_ingestion/general/region';
    public const INGESTION_FALLBACK_ENABLED = 'algoliasearch_ingestion/general/fallback_to_batch';

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
