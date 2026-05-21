<?php

namespace Algolia\Ingestion\Test\Unit\Console\Command\Ingestion;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Console\Command\Ingestion\IngestionResetCommand;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\Collection;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
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
            $this->state,
            $this->storeNameFetcher
        );
    }
}
