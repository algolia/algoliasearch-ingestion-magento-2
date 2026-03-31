<?php

namespace Algolia\Ingestion\Api;

use Algolia\AlgoliaSearch\Api\ClientProviderInterface;
use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;

interface IngestionClientProviderInterface extends ClientProviderInterface
{
    /**
     * @throws AlgoliaException
     */
    public function getClient(?int $storeId = self::ALGOLIA_DEFAULT_SCOPE): IngestionClient;
}
