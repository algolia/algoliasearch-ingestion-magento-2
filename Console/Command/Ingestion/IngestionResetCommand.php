<?php

namespace Algolia\Ingestion\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Console\Command\Ingestion\Renderer\CleanupReportRenderer;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Algolia\Ingestion\Service\IngestionCleanupService;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class IngestionResetCommand extends AbstractIngestionCommand
{
    private const OPTION_API_CLEANUP = 'api-cleanup';
    private const OPTION_FORCE       = 'force';

    public function __construct(
        protected StoreManagerInterface         $storeManager,
        protected IngestionTaskServiceInterface $taskService,
        protected CollectionFactory             $collectionFactory,
        protected IngestionTaskResource         $taskResource,
        protected IngestionCleanupService       $cleanupService,
        protected CleanupReportRenderer         $reportRenderer,
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
        return 'Clear the local Ingestion API task cache. Optionally also tears down the matching Algolia-side resources via --api-cleanup.';
    }

    protected function getStoreArgumentDescription(): string
    {
        return 'ID(s) for store(s) to reset (optional). If not specified, all stores are reset.';
    }

    protected function getAdditionalDefinition(): array
    {
        return [
            new InputOption(
                self::OPTION_API_CLEANUP,
                null,
                InputOption::VALUE_NONE,
                'Also delete the matching Algolia-side task, source, destination, and authentication for Magento-owned rows.'
            ),
            new InputOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Skip the confirmation prompt. With --api-cleanup, readonly safety checks always run.'
            ),
        ];
    }

    /**
     * @throws AlgoliaException|LocalizedException
     */
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

        $force = (bool) $input->getOption(self::OPTION_FORCE);

        if ($input->getOption(self::OPTION_API_CLEANUP)) {
            return $this->executeApiCleanup($filteredStoreIds, $force, $output);
        }

        return $this->executeLocalOnly($filteredStoreIds, $force, $output);
    }

    /**
     * @param int[] $filteredStoreIds
     * @throws LocalizedException
     */
    private function executeLocalOnly(array $filteredStoreIds, bool $force, OutputInterface $output): int
    {
        $output->writeln(
            '<comment>NOTE: This clears local cache only. No resources will be modified in Algolia.</comment>'
        );

        if (!$force && !$this->confirmOperation('Reset confirmed', 'Operation cancelled')) {
            return Cli::RETURN_SUCCESS;
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

    /**
     * @param int[] $filteredStoreIds
     * @throws AlgoliaException
     */
    private function executeApiCleanup(array $filteredStoreIds, bool $force, OutputInterface $output): int
    {
        $plan = $this->cleanupService->buildPlan($filteredStoreIds);
        $this->reportRenderer->renderPreview($plan, $output);

        if ($plan->isEmpty()) {
            return Cli::RETURN_SUCCESS;
        }

        if (!$force && !$this->confirmCleanup()) {
            $output->writeln('<comment>Operation cancelled</comment>');
            return Cli::RETURN_SUCCESS;
        }

        $result = $this->cleanupService->execute($plan);
        $this->reportRenderer->renderResult($result, $output);

        return $result->failureCount() > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }

    private function confirmCleanup(): bool
    {
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>Proceed? [y/N]</question> ', false);
        return (bool) $helper->ask($this->input, $this->output, $question);
    }

    /**
     * @throws LocalizedException
     */
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
