<?php

namespace Algolia\Ingestion\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Helper\Entity\CategoryHelper;
use Algolia\AlgoliaSearch\Helper\Entity\PageHelper;
use Algolia\AlgoliaSearch\Helper\Entity\ProductHelper;
use Algolia\AlgoliaSearch\Helper\Entity\SuggestionHelper;
use Algolia\AlgoliaSearch\Service\IndexOptionsBuilder;
use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IngestionInitCommand extends AbstractIngestionCommand
{
    /** Standard entity index suffixes to warm on init. Additional sections warm on first push. */
    private const ENTITY_SUFFIXES = [
        ProductHelper::INDEX_NAME_SUFFIX,
        CategoryHelper::INDEX_NAME_SUFFIX,
        PageHelper::INDEX_NAME_SUFFIX,
        SuggestionHelper::INDEX_NAME_SUFFIX,
    ];

    public function __construct(
        protected StoreManagerInterface $storeManager,
        protected IngestionTaskServiceInterface $taskService,
        protected IndexOptionsBuilder $indexOptionsBuilder,
        protected IngestionConfigHelper $ingestionConfigHelper,
        State $state,
        StoreNameFetcher $storeNameFetcher,
        ?string $name = null
    ) {
        parent::__construct($storeManager, $state, $storeNameFetcher, $name);
    }

    protected function getCommandName(): string
    {
        return 'init';
    }

    protected function getCommandDescription(): string
    {
        return 'Proactively warm the Ingestion API task cache for all configured Algolia indices. Idempotent — skips indices with existing cache entries.';
    }

    protected function getStoreArgumentDescription(): string
    {
        return 'ID(s) for store(s) to initialize (optional). If not specified, all enabled stores are initialized.';
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
            $storeIds = $filteredStoreIds ?: array_keys($this->storeManager->getStores());
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $target = $filteredStoreIds
            ? implode(', ', $filteredStoreIds)
            : 'all stores';
        $output->writeln("<info>Initializing Algolia Ingestion task cache for $target...</info>");

        $totalWarmed = 0;
        $totalSkipped = 0;

        foreach ($storeIds as $storeId) {
            [$warmed, $skipped] = $this->initializeStore((int) $storeId, $output);
            $totalWarmed += $warmed;
            $totalSkipped += $skipped;
        }

        $output->writeln('');
        $output->writeln("<info>Done. $totalWarmed index/task pair(s) resolved, $totalSkipped store(s) skipped (ingestion disabled).</info>");

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @return array{int, int} [warmed count, skipped count]
     */
    private function initializeStore(int $storeId, OutputInterface $output): array
    {
        try {
            $storeName = $this->storeNameFetcher->getStoreName($storeId);
        } catch (NoSuchEntityException $e) {
            $output->writeln("<comment>  Store $storeId: not found, skipping.</comment>");
            return [0, 1];
        }

        if (!$this->ingestionConfigHelper->isEnabled($storeId)) {
            $output->writeln("<comment>  Store $storeId ($storeName): ingestion disabled, skipping.</comment>");
            return [0, 1];
        }

        $output->writeln("<info>  Store $storeId ($storeName):</info>");
        $warmed = 0;

        foreach (self::ENTITY_SUFFIXES as $suffix) {
            try {
                $indexOptions = $this->indexOptionsBuilder->buildWithComputedIndex($suffix, $storeId);
                $taskId = $this->taskService->getTaskId($indexOptions);
                $output->writeln("    <info>v</info> {$indexOptions->getIndexName()} -> $taskId");
                $warmed++;
            } catch (\Exception $e) {
                $output->writeln("    <error>x $suffix: {$e->getMessage()}</error>");
            }
        }

        return [$warmed, 0];
    }
}
