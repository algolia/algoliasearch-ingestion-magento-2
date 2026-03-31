<?php

namespace Algolia\Ingestion\Service;

use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Configuration\IngestionConfig;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Algolia\AlgoliaSearch\Service\AlgoliaCredentialsManager;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Helper\IngestionConfigHelper;

class IngestionClientProvider implements IngestionClientProviderInterface
{
    /** @var IngestionClient[] */
    protected array $clients = [];

    public function __construct(
        protected ConfigHelper $config,
        protected IngestionConfigHelper $ingestionConfigHelper,
        protected AlgoliaCredentialsManager $algoliaCredentialsManager
    ) {}

    /**
     * @throws AlgoliaException
     */
    public function getClient(?int $storeId = self::ALGOLIA_DEFAULT_SCOPE): IngestionClient
    {
        if ($storeId === null) {
            $storeId = self::ALGOLIA_DEFAULT_SCOPE;
        }

        if (!isset($this->clients[$storeId])) {
            $this->createClient($storeId);
        }

        return $this->clients[$storeId];
    }

    /**
     * @throws AlgoliaException
     */
    protected function createClient(int $storeId = self::ALGOLIA_DEFAULT_SCOPE): void
    {
        if (!$this->algoliaCredentialsManager->checkCredentials($storeId)) {
            throw new AlgoliaException('Client initialization could not be performed because Algolia credentials were not provided.');
        }

        $config = IngestionConfig::create(
            $this->config->getApplicationID($storeId),
            $this->config->getAPIKey($storeId),
            $this->ingestionConfigHelper->getRegion($storeId),
        );

        $config->setConnectTimeout($this->config->getConnectionTimeout($storeId));
        $config->setReadTimeout($this->config->getReadTimeout($storeId));
        $config->setWriteTimeout($this->config->getWriteTimeout($storeId));
        $this->clients[$storeId] = IngestionClient::createWithConfig($config);
    }
}
