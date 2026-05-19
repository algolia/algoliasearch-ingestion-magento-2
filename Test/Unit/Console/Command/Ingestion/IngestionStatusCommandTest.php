<?php

namespace Algolia\Ingestion\Test\Unit\Console\Command\Ingestion;

use Algolia\Ingestion\Console\Command\Ingestion\IngestionStatusCommand;
use Algolia\Ingestion\Model\IngestionTask;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\Collection;
use Algolia\Ingestion\Model\ResourceModel\IngestionTask\CollectionFactory as TaskCollectionFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;

class IngestionStatusCommandTest extends AbstractIngestionCommandTestCase
{
    private null|(TaskCollectionFactory&MockObject) $collectionFactory = null;
    private null|(Collection&MockObject) $collection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactory = $this->createMock(TaskCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->ingestionConfigHelper->method('isEnabled')->willReturn(true);
    }

    // --- execute() branches ---

    public function testExecuteReturnsFailureOnInvalidStoreId(): void
    {
        $this->collectionFactory->expects($this->never())->method('create');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, ['abc']), $this->bufOut());

        $this->assertSame(Cli::RETURN_FAILURE, $code);
    }

    public function testExecuteHandlesEmptyCacheWithoutRenderingTables(): void
    {
        $this->storeNameFetcher->expects($this->never())->method('getStoreName');
        $this->ingestionConfigHelper->expects($this->never())->method('isEnabled');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, []), $this->bufOut());

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- loadTasksGroupedByStore() ---

    public function testLoadTasksGroupedByStoreSkipsFilterWhenNoStoreIds(): void
    {
        $this->collection->expects($this->never())->method('addFieldToFilter');

        $orderCalls = [];
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collection->expects($this->exactly(2))
            ->method('setOrder')
            ->willReturnCallback(function (string $field, string $dir) use (&$orderCalls) {
                $orderCalls[] = [$field, $dir];
                return $this->collection;
            });
        $this->collectionFactory = $this->createMock(TaskCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $cmd = $this->makeReal();
        $this->invokeMethod($cmd, 'loadTasksGroupedByStore', [[]]);

        $this->assertSame(
            [['store_id', 'ASC'], ['index_name', 'ASC']],
            $orderCalls
        );
    }

    public function testLoadTasksGroupedByStoreAppliesInFilterPredicate(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('store_id', ['in' => [1, 2]])
            ->willReturnSelf();
        $this->collectionFactory = $this->createMock(TaskCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $cmd = $this->makeReal();
        $this->invokeMethod($cmd, 'loadTasksGroupedByStore', [[1, 2]]);
    }

    public function testLoadTasksGroupedByStoreGroupsResultsByStoreId(): void
    {
        $tasks = [
            $this->mockTask(1, 'idx_a'),
            $this->mockTask(1, 'idx_b'),
            $this->mockTask(2, 'idx_a'),
            $this->mockTask(1, 'idx_c'),
        ];

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($tasks));
        $this->collectionFactory = $this->createMock(TaskCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $cmd = $this->makeReal();
        $grouped = $this->invokeMethod($cmd, 'loadTasksGroupedByStore', [[]]);

        $this->assertSame([1, 2], array_keys($grouped));
        $this->assertCount(3, $grouped[1]);
        $this->assertCount(1, $grouped[2]);
        $this->assertSame($tasks[0], $grouped[1][0]);
        $this->assertSame($tasks[1], $grouped[1][1]);
        $this->assertSame($tasks[3], $grouped[1][2]);
        $this->assertSame($tasks[2], $grouped[2][0]);
    }

    // --- renderStoreTaskTable() ---

    public function testRenderHandlesUnknownStoreWithoutThrowing(): void
    {
        $this->storeNameFetcher = $this->createMock(\Algolia\AlgoliaSearch\Service\StoreNameFetcher::class);
        $this->storeNameFetcher->method('getStoreName')
            ->willThrowException(new NoSuchEntityException());

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(
            new \ArrayIterator([$this->mockTask(99, 'idx_a')])
        );
        $this->collectionFactory = $this->createMock(TaskCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $cmd = $this->makePartial(['setAreaCode']);
        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, []), $this->bufOut());

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteCallsPerStoreCollaboratorsOncePerStore(): void
    {
        $tasks = [
            $this->mockTask(1, 'idx_a'),
            $this->mockTask(1, 'idx_b'),
            $this->mockTask(2, 'idx_a'),
        ];

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($tasks));
        $this->collectionFactory = $this->createMock(TaskCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $isEnabledCalls = [];
        $this->ingestionConfigHelper = $this->createMock(\Algolia\Ingestion\Helper\IngestionConfigHelper::class);
        $this->ingestionConfigHelper->method('isEnabled')
            ->willReturnCallback(function (int $id) use (&$isEnabledCalls) {
                $isEnabledCalls[] = $id;
                return true;
            });

        $nameCalls = [];
        $this->storeNameFetcher = $this->createMock(\Algolia\AlgoliaSearch\Service\StoreNameFetcher::class);
        $this->storeNameFetcher->method('getStoreName')
            ->willReturnCallback(function (int $id) use (&$nameCalls) {
                $nameCalls[] = $id;
                return "Store $id";
            });

        $cmd = $this->makePartial(['setAreaCode']);
        $code = $this->invokeExecute($cmd, $this->arrayInput($cmd, []), $this->bufOut());

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
        $this->assertSame([1, 2], $isEnabledCalls);
        $this->assertSame([1, 2], $nameCalls);
    }

    // --- cross-cutting ---

    public function testCommandName(): void
    {
        $this->assertSame('algolia:ingestion:status', $this->makeReal()->getName());
    }

    // --- helpers ---

    private function makePartial(array $methodsToMock = []): IngestionStatusCommand&MockObject
    {
        return $this->getMockBuilder(IngestionStatusCommand::class)
            ->setConstructorArgs([
                $this->storeManager,
                $this->collectionFactory,
                $this->ingestionConfigHelper,
                $this->state,
                $this->storeNameFetcher,
                null,
            ])
            ->onlyMethods($methodsToMock)
            ->getMock();
    }

    private function makeReal(): IngestionStatusCommand
    {
        return new IngestionStatusCommand(
            $this->storeManager,
            $this->collectionFactory,
            $this->ingestionConfigHelper,
            $this->state,
            $this->storeNameFetcher
        );
    }

    private function mockTask(int $storeId, string $indexName): IngestionTask&MockObject
    {
        $task = $this->createMock(IngestionTask::class);
        $task->method('getData')->willReturnCallback(function (string $key) use ($storeId, $indexName) {
            return match ($key) {
                'store_id'   => $storeId,
                'index_name' => $indexName,
                'task_id'    => 'task-uuid',
                'created_at' => '2026-05-18 12:00:00',
                default      => null,
            };
        });
        return $task;
    }
}
