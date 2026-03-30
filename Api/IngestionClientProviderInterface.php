<?php

namespace Algolia\Ingestion\Api;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;

interface IngestionClientProviderInterface
{
    /**
     * @throws AlgoliaException
     */
    public function getClient(?int $storeId = 0): IngestionClient;
}
