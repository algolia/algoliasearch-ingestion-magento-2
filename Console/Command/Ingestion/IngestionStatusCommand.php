<?php

namespace Algolia\Ingestion\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IngestionStatusCommand extends AbstractIngestionCommand
{
    public function __construct(
        protected StoreManagerInterface $storeManager,
        protected CollectionFactory $collectionFactory,
        protected IngestionConfigHelper $ingestionConfigHelper,
        State $state,
        StoreNameFetcher $storeNameFetcher,
        ?string $name = null
    ) {
        parent::__construct($storeManager, $state, $storeNameFetcher, $name);
    }

    protected function getCommandName(): string
    {
        return 'status';
    }

    protected function getCommandDescription(): string
    {
        return 'Display Algolia Ingestion API pipeline task cache status per store and index.';
    }

    protected function getStoreArgumentDescription(): string
    {
        return 'ID(s) for store(s) to display status for (optional). If not specified, all stores are shown.';
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

        try {
            $filteredStoreIds = $this->getStoreIds($input);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $collection = $this->collectionFactory->create();
        if (!empty($filteredStoreIds)) {
            $collection->addFieldToFilter('store_id', ['in' => $filteredStoreIds]);
        }
        $collection->setOrder('store_id', 'ASC');
        $collection->setOrder('index_name', 'ASC');

        $byStore = [];
        foreach ($collection as $task) {
            $byStore[(int) $task->getData('store_id')][] = $task;
        }

        if (empty($byStore)) {
            $output->writeln('<comment>No ingestion task cache entries found.</comment>');
            return Cli::RETURN_SUCCESS;
        }

        foreach ($byStore as $storeId => $tasks) {
            try {
                $storeName = $this->storeNameFetcher->getStoreName($storeId);
            } catch (NoSuchEntityException $e) {
                $storeName = 'Unknown';
            }

            $enabled = $this->ingestionConfigHelper->isEnabled($storeId);
            $enabledLabel = $enabled ? '<info>ENABLED</info>' : '<comment>DISABLED</comment>';

            $output->writeln('');
            $output->writeln("Store $storeId: $storeName [$enabledLabel]");

            $table = new Table($output);
            $table->setHeaders(['Index Name', 'Task ID', 'Created At']);

            foreach ($tasks as $task) {
                $table->addRow([
                    $task->getData('index_name'),
                    $task->getData('task_id'),
                    $task->getData('created_at'),
                ]);
            }

            $table->render();
        }

        $output->writeln('');
        return Cli::RETURN_SUCCESS;
    }
}
