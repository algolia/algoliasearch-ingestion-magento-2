<?php

namespace Algolia\Ingestion\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IngestionResetCommand extends AbstractIngestionCommand
{
    public function __construct(
        protected StoreManagerInterface         $storeManager,
        protected IngestionTaskServiceInterface $taskService,
        protected CollectionFactory             $collectionFactory,
        protected IngestionTaskResource         $taskResource,
        State                                   $state,
        StoreNameFetcher                        $storeNameFetcher,
        ?string                                 $name = null
    ) {
        parent::__construct($storeManager, $state, $storeNameFetcher, $name);
    }

    protected function getCommandName(): string
    {
        return 'reset';
    }

    protected function getCommandDescription(): string
    {
        return 'Clear the local Ingestion API task cache. Does not modify any resources in Algolia.';
    }

    protected function getStoreArgumentDescription(): string
    {
        return 'ID(s) for store(s) to reset (optional). If not specified, all stores are reset.';
    }

    protected function getAdditionalDefinition(): array
    {
        return [];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->setAreaCode();

        $output->writeln('<comment>NOTE: This clears local cache only. No resources will be modified in Algolia.</comment>');

        if (!$this->confirmOperation('Reset confirmed', 'Operation cancelled')) {
            return Cli::RETURN_SUCCESS;
        }

        try {
            $filteredStoreIds = $this->getStoreIds($input);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        if (empty($filteredStoreIds)) {
            $this->resetAll($output);
        } else {
            foreach ($filteredStoreIds as $storeId) {
                $this->resetStore($storeId, $output);
            }
        }

        $output->writeln('<info>Ingestion task cache reset complete.</info>');
        return Cli::RETURN_SUCCESS;
    }

    private function resetAll(OutputInterface $output): void
    {
        $count = $this->collectionFactory->create()->getSize();
        $this->taskResource->getConnection()->truncateTable($this->taskResource->getMainTable());
        $output->writeln("<info>Cleared $count cache record(s) across all stores.</info>");
    }

    private function resetStore(int $storeId, OutputInterface $output): void
    {
        try {
            $storeName = $this->storeNameFetcher->getStoreName($storeId);
        } catch (NoSuchEntityException $e) {
            $storeName = "store $storeId";
        }

        $this->taskService->invalidateByStoreId($storeId);
        $output->writeln("<info>✓ Cleared cache for $storeName (store $storeId)</info>");
    }
}
