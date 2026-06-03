<?php

namespace Algolia\Ingestion\Test\Unit\Console\Command\Ingestion;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Console\Command\Ingestion\IngestionResetCommand;
use Algolia\Ingestion\Console\Command\Ingestion\Renderer\CleanupReportRenderer;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\Collection;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Algolia\Ingestion\Model\Cleanup\CleanupPlan;
use Algolia\Ingestion\Model\Cleanup\CleanupResult;
use Algolia\Ingestion\Model\Cleanup\ObjectPlan;
use Algolia\Ingestion\Model\Cleanup\RowPlan;
use Algolia\Ingestion\Model\Cleanup\RowResult;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Service\IngestionCleanupService;
use Magento\Framework\Console\Cli;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class IngestionResetCommandTest extends AbstractIngestionCommandTestCase
{
    private null|(IngestionTaskServiceInterface&MockObject) $taskService = null;
    private null|(CollectionFactory&MockObject) $collectionFactory = null;
    private null|(Collection&MockObject) $collection = null;
    private null|(IngestionTaskResource&MockObject) $taskResource = null;
    private null|(AdapterInterface&MockObject) $connection = null;
    private null|(IngestionCleanupService&MockObject) $cleanupService = null;
    private null|(CleanupReportRenderer&MockObject) $reportRenderer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taskService       = $this->createMock(IngestionTaskServiceInterface::class);
        $this->collection        = $this->createMock(Collection::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->connection   = $this->createMock(AdapterInterface::class);
        $this->taskResource = $this->createMock(IngestionTaskResource::class);
        $this->taskResource->method('getConnection')->willReturn($this->connection);
        $this->taskResource->method('getMainTable')->willReturn(IngestionTaskResource::TABLE_NAME);

        $this->cleanupService = $this->createMock(IngestionCleanupService::class);
        $this->reportRenderer = $this->createMock(CleanupReportRenderer::class);
    }

    // --- confirmation ---

    public function testExecuteReturnsSuccessWhenConfirmationDeclined(): void
    {
        $cmd = $this->makePartial(['setAreaCode']);

        $this->connection->expects($this->never())->method('truncateTable');
        $this->taskService->expects($this->never())->method('invalidateByStoreId');

        $tester = new CommandTester($cmd);
        $tester->setInputs(['n']);
        $code = $tester->execute([]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- resetAll / resetStore branching ---

    public function testExecuteResetsAllWhenNoStoreIdsProvided(): void
    {
        $this->collection->expects($this->once())->method('getSize')->willReturn(7);
        $this->connection->expects($this->once())
            ->method('truncateTable')
            ->with(IngestionTaskResource::TABLE_NAME);
        $this->taskService->expects($this->never())->method('invalidateByStoreId');

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $tester->setInputs(['y']);
        $code = $tester->execute([]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteResetsSingleStoreOnly(): void
    {
        $this->connection->expects($this->never())->method('truncateTable');
        $this->taskService->expects($this->once())
            ->method('invalidateByStoreId')
            ->with(1);

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $tester->setInputs(['y']);
        $code = $tester->execute(['store_id' => ['1']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteResetsEachRequestedStoreExactlyOnceAndPromptsOnce(): void
    {
        $cmd = $this->makePartial(['setAreaCode']);

        $seen = [];
        $this->taskService->expects($this->exactly(3))
            ->method('invalidateByStoreId')
            ->willReturnCallback(function (int $id) use (&$seen) {
                $seen[] = $id;
            });

        $tester = new CommandTester($cmd);
        // Single 'y' covers the one expected prompt; a second prompt would hang/fail
        // because no further input is queued — that is what guards the "prompts once" contract.
        $tester->setInputs(['y']);
        $code = $tester->execute(['store_id' => ['2', '5', '7']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
        $this->assertEqualsCanonicalizing([2, 5, 7], $seen);
    }

    public function testResetStoreStillInvalidatesWhenStoreNameLookupFails(): void
    {
        $this->storeNameFetcher = $this->createMock(\Algolia\AlgoliaSearch\Service\StoreNameFetcher::class);
        $this->storeNameFetcher->method('getStoreName')
            ->willThrowException(new NoSuchEntityException());

        $this->taskService->expects($this->once())
            ->method('invalidateByStoreId')
            ->with(42);

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $tester->setInputs(['y']);
        $code = $tester->execute(['store_id' => ['42']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- --force flag ---

    public function testExecuteSkipsLocalConfirmationPromptWhenForceFlagPassed(): void
    {
        $this->connection->expects($this->once())
            ->method('truncateTable')
            ->with(IngestionTaskResource::TABLE_NAME);
        $this->collection->method('getSize')->willReturn(0);

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        // No inputs queued — if the command tried to prompt, the test would hang/fail.
        $code = $tester->execute(['--force' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- --api-cleanup flag ---

    public function testExecuteWithApiCleanupBuildsPlanAndExecutesAfterConfirmation(): void
    {
        $plan = $this->buildNonEmptyPlan();
        $result = $this->buildSuccessfulResult($plan);

        $this->cleanupService->expects($this->once())
            ->method('buildPlan')
            ->with([])
            ->willReturn($plan);
        $this->reportRenderer->expects($this->once())->method('renderPreview')->with($plan);
        $this->cleanupService->expects($this->once())
            ->method('execute')
            ->with($plan)
            ->willReturn($result);
        $this->reportRenderer->expects($this->once())->method('renderResult')->with($result);

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $tester->setInputs(['y']);
        $code = $tester->execute(['--api-cleanup' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteWithApiCleanupAndForceSkipsConfirmationPromptAndExecutes(): void
    {
        $plan = $this->buildNonEmptyPlan();
        $result = $this->buildSuccessfulResult($plan);

        $this->cleanupService->method('buildPlan')->willReturn($plan);
        $this->cleanupService->expects($this->once())
            ->method('execute')
            ->with($plan)
            ->willReturn($result);

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        // No inputs queued — confirmation must be bypassed.
        $code = $tester->execute(['--api-cleanup' => true, '--force' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteWithApiCleanupSkipsExecuteWhenUserDeclines(): void
    {
        $plan = $this->buildNonEmptyPlan();

        $this->cleanupService->method('buildPlan')->willReturn($plan);
        $this->cleanupService->expects($this->never())->method('execute');
        $this->reportRenderer->expects($this->never())->method('renderResult');

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $tester->setInputs(['n']);
        $code = $tester->execute(['--api-cleanup' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteWithApiCleanupShortCircuitsOnEmptyPlanWithoutPrompting(): void
    {
        $emptyPlan = new CleanupPlan([], [], new \DateTimeImmutable());

        $this->cleanupService->method('buildPlan')->willReturn($emptyPlan);
        $this->reportRenderer->expects($this->once())->method('renderPreview')->with($emptyPlan);
        $this->cleanupService->expects($this->never())->method('execute');

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        // Even without --force, an empty plan must not prompt — no inputs queued.
        $code = $tester->execute(['--api-cleanup' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteWithApiCleanupReturnsFailureWhenAnyRowFailed(): void
    {
        $plan = $this->buildNonEmptyPlan();
        $failedResult = new CleanupResult([
            new RowResult($plan->rows[0], RowResult::STATUS_FAILED, 1, 0, 'destination', 'boom'),
        ]);

        $this->cleanupService->method('buildPlan')->willReturn($plan);
        $this->cleanupService->method('execute')->willReturn($failedResult);

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $code = $tester->execute(['--api-cleanup' => true, '--force' => true]);

        $this->assertSame(Cli::RETURN_FAILURE, $code);
    }

    public function testExecuteWithApiCleanupForwardsStoreIdsToBuildPlan(): void
    {
        $plan = new CleanupPlan([], [1, 2], new \DateTimeImmutable());

        $this->cleanupService->expects($this->once())
            ->method('buildPlan')
            ->with([1, 2])
            ->willReturn($plan);

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $code = $tester->execute(['store_id' => ['1', '2'], '--api-cleanup' => true, '--force' => true]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- arg validation ---

    public function testExecuteReturnsFailureOnInvalidStoreIdWithoutMutating(): void
    {
        $this->connection->expects($this->never())->method('truncateTable');
        $this->taskService->expects($this->never())->method('invalidateByStoreId');

        $cmd = $this->makePartial(['setAreaCode']);

        $tester = new CommandTester($cmd);
        $tester->setInputs(['y']);
        $code = $tester->execute(['store_id' => ['abc']]);

        $this->assertSame(Cli::RETURN_FAILURE, $code);
    }

    // --- cross-cutting ---

    public function testCommandName(): void
    {
        $this->assertSame('algolia:ingestion:reset', $this->makeReal()->getName());
    }

    // --- helpers ---

    private function makePartial(array $methodsToMock = []): IngestionResetCommand&MockObject
    {
        $cmd = $this->getMockBuilder(IngestionResetCommand::class)
            ->setConstructorArgs([
                $this->storeManager,
                $this->taskService,
                $this->collectionFactory,
                $this->taskResource,
                $this->cleanupService,
                $this->reportRenderer,
                $this->state,
                $this->storeNameFetcher,
                null,
            ])
            ->onlyMethods($methodsToMock)
            ->getMock();

        // Attach an Application so the `question` helper resolves under CommandTester.
        // CommandTester does not wire HelperSet itself; that comes from Application.
        $cmd->setApplication(new Application());

        return $cmd;
    }

    private function makeReal(): IngestionResetCommand
    {
        return new IngestionResetCommand(
            $this->storeManager,
            $this->taskService,
            $this->collectionFactory,
            $this->taskResource,
            $this->cleanupService,
            $this->reportRenderer,
            $this->state,
            $this->storeNameFetcher
        );
    }

    private function buildNonEmptyPlan(): CleanupPlan
    {
        $task = $this->createMock(IngestionTask::class);
        $row = new RowPlan(
            $task,
            1,
            'idx',
            1,
            'Magento',
            [
                RowPlan::OBJECT_TASK           => ObjectPlan::delete('t'),
                RowPlan::OBJECT_SOURCE         => ObjectPlan::delete('s'),
                RowPlan::OBJECT_DESTINATION    => ObjectPlan::delete('d'),
                RowPlan::OBJECT_AUTHENTICATION => ObjectPlan::delete('a'),
            ]
        );
        return new CleanupPlan([$row], [], new \DateTimeImmutable());
    }

    private function buildSuccessfulResult(CleanupPlan $plan): CleanupResult
    {
        return new CleanupResult([
            new RowResult($plan->rows[0], RowResult::STATUS_SUCCESS, 4, 0),
        ]);
    }
}
