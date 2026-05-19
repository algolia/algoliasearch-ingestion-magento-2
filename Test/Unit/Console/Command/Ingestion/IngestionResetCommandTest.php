<?php

namespace Algolia\Ingestion\Test\Unit\Console\Command\Ingestion;

use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Console\Command\Ingestion\IngestionResetCommand;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask as IngestionTaskResource;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\Collection;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;

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
        $cmd = $this->makePartial(['setAreaCode', 'confirmOperation']);
        $cmd->method('confirmOperation')->willReturn(false);

        $this->connection->expects($this->never())->method('truncateTable');
        $this->taskService->expects($this->never())->method('invalidateByStoreId');

        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, []), $this->bufOut());

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

        $cmd = $this->makePartial(['setAreaCode', 'confirmOperation']);
        $cmd->method('confirmOperation')->willReturn(true);

        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, []), $this->bufOut());

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteResetsSingleStoreOnly(): void
    {
        $this->connection->expects($this->never())->method('truncateTable');
        $this->taskService->expects($this->once())
            ->method('invalidateByStoreId')
            ->with(1);

        $cmd = $this->makePartial(['setAreaCode', 'confirmOperation']);
        $cmd->method('confirmOperation')->willReturn(true);

        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, ['1']), $this->bufOut());

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteResetsMultipleStoresInOrderAndPromptsOnce(): void
    {
        $cmd = $this->makePartial(['setAreaCode', 'confirmOperation']);
        $cmd->expects($this->once())->method('confirmOperation')->willReturn(true);

        $seen = [];
        $this->taskService->expects($this->exactly(3))
            ->method('invalidateByStoreId')
            ->willReturnCallback(function (int $id) use (&$seen) {
                $seen[] = $id;
            });

        $code = $this->invokeExecute(
            $cmd,
            $this->arrayInput($cmd, ['2', '5', '7']),
            $this->bufOut()
        );

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
        $this->assertSame([2, 5, 7], $seen);
    }

    public function testResetStoreStillInvalidatesWhenStoreNameLookupFails(): void
    {
        $this->storeNameFetcher = $this->createMock(\Algolia\AlgoliaSearch\Service\StoreNameFetcher::class);
        $this->storeNameFetcher->method('getStoreName')
            ->willThrowException(new NoSuchEntityException());

        $this->taskService->expects($this->once())
            ->method('invalidateByStoreId')
            ->with(42);

        $cmd = $this->makePartial(['setAreaCode', 'confirmOperation']);
        $cmd->method('confirmOperation')->willReturn(true);

        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, ['42']), $this->bufOut());

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- arg validation ---

    public function testExecuteReturnsFailureOnInvalidStoreIdWithoutMutating(): void
    {
        $this->connection->expects($this->never())->method('truncateTable');
        $this->taskService->expects($this->never())->method('invalidateByStoreId');

        $cmd = $this->makePartial(['setAreaCode', 'confirmOperation']);
        $cmd->method('confirmOperation')->willReturn(true);

        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, ['abc']), $this->bufOut());

        $this->assertSame(Cli::RETURN_FAILURE, $code);
    }

    // --- cross-cutting ---

    public function testCommandHasExpectedStoreIdArgument(): void
    {
        $cmd = $this->makeReal();
        $args = $cmd->getDefinition()->getArguments();

        $this->assertCount(1, $args);
        $this->assertArrayHasKey('store_id', $args);
        $this->assertTrue($args['store_id']->isArray());
        $this->assertFalse($args['store_id']->isRequired());
    }

    public function testCommandName(): void
    {
        $this->assertSame('algolia:ingestion:reset', $this->makeReal()->getName());
    }

    public function testAreaCodeFailureIsSwallowedAndExecutionContinues(): void
    {
        $this->state->method('setAreaCode')
            ->willThrowException(new LocalizedException(__('already set')));

        $cmd = $this->makePartial(['confirmOperation']);
        $cmd->method('confirmOperation')->willReturn(true);

        $this->taskService->expects($this->once())
            ->method('invalidateByStoreId')
            ->with(1);

        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, ['1']), $this->bufOut());

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- helpers ---

    private function makePartial(array $methodsToMock = []): IngestionResetCommand&MockObject
    {
        return $this->getMockBuilder(IngestionResetCommand::class)
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
