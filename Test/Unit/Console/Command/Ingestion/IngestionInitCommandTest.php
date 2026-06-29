<?php

namespace Algolia\Ingestion\Test\Unit\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Helper\Entity\CategoryHelper;
use Algolia\AlgoliaSearch\Service\Index\IndexOptionsBuilder;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Console\Command\Ingestion\IngestionInitCommand;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Tester\CommandTester;

class IngestionInitCommandTest extends AbstractIngestionCommandTestCase
{
    private null|(IngestionTaskServiceInterface&MockObject) $taskService = null;
    private null|(IndexOptionsBuilder&MockObject) $indexOptionsBuilder = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taskService         = $this->createMock(IngestionTaskServiceInterface::class);
        $this->indexOptionsBuilder = $this->createMock(IndexOptionsBuilder::class);

        $this->ingestionConfigHelper->method('isEnabled')->willReturn(true);
        $this->indexOptionsBuilder->method('buildWithComputedIndex')
            ->willReturnCallback(fn(string $suffix, int $storeId) => $this->mockIndexOptions($suffix, $storeId));
        $this->taskService->method('getTaskId')->willReturn('task-uuid');
    }

    // --- execute() store filtering ---

    public function testExecuteIteratesAllStoresWhenNoStoreArgProvided(): void
    {
        $this->storeManager->expects($this->once())
            ->method('getStores')
            ->willReturn($this->mockStoresKeyedById([1, 2]));

        $expectedCallCount = count(IngestionInitCommand::ENTITY_SUFFIXES) * 2;
        $this->indexOptionsBuilder->expects($this->exactly($expectedCallCount))
            ->method('buildWithComputedIndex');
        $this->taskService->expects($this->exactly($expectedCallCount))
            ->method('getTaskId');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute([]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteProcessesOnlyProvidedStoreIds(): void
    {
        $this->storeManager->expects($this->never())->method('getStores');

        $seenStoreIds = [];
        $this->taskService->expects($this->exactly(count(IngestionInitCommand::ENTITY_SUFFIXES) * 2))
            ->method('getTaskId')
            ->willReturnCallback(function (IndexOptionsInterface $opts) use (&$seenStoreIds) {
                $seenStoreIds[] = $opts->getStoreId();
                return 'task-uuid';
            });

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute(['store_id' => ['1', '3']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
        $this->assertSame([1, 3], array_values(array_unique($seenStoreIds)));
    }

    public function testExecuteReturnsFailureOnInvalidStoreId(): void
    {
        $this->indexOptionsBuilder->expects($this->never())->method('buildWithComputedIndex');
        $this->taskService->expects($this->never())->method('getTaskId');
        $this->storeManager->expects($this->never())->method('getStores');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute(['store_id' => ['abc']]);

        $this->assertSame(Cli::RETURN_FAILURE, $code);
    }

    // --- initializeStore() branches ---

    public function testInitializeStoreSkipsWhenStoreNotFound(): void
    {
        $this->storeNameFetcher = $this->createMock(\Algolia\AlgoliaSearch\Service\StoreNameFetcher::class);
        $this->storeNameFetcher->method('getStoreName')
            ->willReturnCallback(function (int $id) {
                if ($id === 9) {
                    throw new NoSuchEntityException();
                }
                return "Store $id";
            });

        $this->indexOptionsBuilder->expects($this->exactly(count(IngestionInitCommand::ENTITY_SUFFIXES)))
            ->method('buildWithComputedIndex');
        $this->taskService->expects($this->exactly(count(IngestionInitCommand::ENTITY_SUFFIXES)))
            ->method('getTaskId');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute(['store_id' => ['9', '1']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testInitializeStoreSkipsWhenIngestionDisabled(): void
    {
        $this->ingestionConfigHelper = $this->createMock(\Algolia\Ingestion\Helper\IngestionConfigHelper::class);
        $this->ingestionConfigHelper->method('isEnabled')
            ->willReturnCallback(fn(int $id) => $id !== 2);

        $this->taskService->expects($this->exactly(count(IngestionInitCommand::ENTITY_SUFFIXES)))
            ->method('getTaskId');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute(['store_id' => ['2', '1']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testInitializeStoreWarmsAllEntitySuffixesOnHappyPath(): void
    {
        $seenSuffixes = [];
        $this->indexOptionsBuilder = $this->createMock(IndexOptionsBuilder::class);
        $this->indexOptionsBuilder->method('buildWithComputedIndex')
            ->willReturnCallback(function (string $suffix, int $storeId) use (&$seenSuffixes) {
                $seenSuffixes[] = $suffix;
                return $this->mockIndexOptions($suffix, $storeId);
            });

        $this->taskService->expects($this->exactly(count(IngestionInitCommand::ENTITY_SUFFIXES)))
            ->method('getTaskId');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute(['store_id' => ['1']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
        $this->assertEqualsCanonicalizing(IngestionInitCommand::ENTITY_SUFFIXES, $seenSuffixes);
    }

    public function testInitializeStoreContinuesWhenOneSuffixThrows(): void
    {
        $this->taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $this->taskService->expects($this->exactly(count(IngestionInitCommand::ENTITY_SUFFIXES)))
            ->method('getTaskId')
            ->willReturnCallback(function (IndexOptionsInterface $opts) {
                if ($opts->getIndexSuffix() === CategoryHelper::INDEX_NAME_SUFFIX) {
                    throw new \Exception('boom');
                }
                return 'task-uuid';
            });

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute(['store_id' => ['1']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testInitializeStoreAbsorbsErrorsForAllSuffixes(): void
    {
        $this->taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $this->taskService->expects($this->exactly(count(IngestionInitCommand::ENTITY_SUFFIXES)))
            ->method('getTaskId')
            ->willThrowException(new \Exception('always fails'));

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute(['store_id' => ['1']]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    public function testExecuteHandlesEmptyStoreManagerResult(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);

        $this->indexOptionsBuilder->expects($this->never())->method('buildWithComputedIndex');
        $this->taskService->expects($this->never())->method('getTaskId');

        $cmd = $this->makePartial(['setAreaCode']);
        $code = (new CommandTester($cmd))->execute([]);

        $this->assertSame(Cli::RETURN_SUCCESS, $code);
    }

    // --- cross-cutting ---

    public function testCommandName(): void
    {
        $this->assertSame('algolia:ingestion:init', $this->makeReal()->getName());
    }

    // --- helpers ---

    private function makePartial(array $methodsToMock = []): IngestionInitCommand&MockObject
    {
        return $this->getMockBuilder(IngestionInitCommand::class)
            ->setConstructorArgs([
                $this->storeManager,
                $this->taskService,
                $this->indexOptionsBuilder,
                $this->ingestionConfigHelper,
                $this->state,
                $this->storeNameFetcher,
                null,
            ])
            ->onlyMethods($methodsToMock)
            ->getMock();
    }

    private function makeReal(): IngestionInitCommand
    {
        return new IngestionInitCommand(
            $this->storeManager,
            $this->taskService,
            $this->indexOptionsBuilder,
            $this->ingestionConfigHelper,
            $this->state,
            $this->storeNameFetcher
        );
    }

    private function mockIndexOptions(string $suffix, int $storeId): IndexOptionsInterface&MockObject
    {
        $opts = $this->createMock(IndexOptionsInterface::class);
        $opts->method('getStoreId')->willReturn($storeId);
        $opts->method('getIndexSuffix')->willReturn($suffix);
        $opts->method('getIndexName')->willReturn("magento_default{$suffix}");
        return $opts;
    }
}
