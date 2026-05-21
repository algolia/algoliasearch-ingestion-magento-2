<?php

namespace Algolia\Ingestion\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Console\Command\AbstractStoreCommand;
use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;

abstract class AbstractIngestionCommand extends AbstractStoreCommand
{
    public function __construct(
        protected StoreManagerInterface $storeManager,
        State                           $state,
        StoreNameFetcher                $storeNameFetcher,
        ?string                         $name = null
    ) {
        parent::__construct($state, $storeNameFetcher, $name);
    }

    protected function getCommandPrefix(): string
    {
        return parent::getCommandPrefix() . 'ingestion:';
    }
}
