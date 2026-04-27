<?php

namespace Algolia\Ingestion\Test\Unit\Service;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Api\IngestionClient;
use Algolia\AlgoliaSearch\Api\LoggerInterface;
use Algolia\AlgoliaSearch\Exceptions\BadRequestException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Service\DirectSendStrategy;
use Algolia\AlgoliaSearch\Service\IndexNameFetcher;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionClientProviderInterface;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Algolia\Ingestion\Service\IngestionSendStrategy;
use PHPUnit\Framework\MockObject\MockObject;

class IngestionSendStrategyTest extends TestCase
{
    private const STORE_ID = 1;
    private const INDEX_NAME = 'test_default_products';
    private const TMP_INDEX_NAME = 'test_default_products_tmp';
    private const TASK_ID = 'task-uuid-1234';

    private null|(IngestionConfigHelper&MockObject) $configHelper = null;
    private null|(IngestionClientProviderInterface&MockObject) $clientProvider = null;
    private null|(IngestionClient&MockObject) $ingestionClient = null;
    private null|(IngestionTaskServiceInterface&MockObject) $taskService = null;
    private null|(IndexNameFetcher&MockObject) $indexNameFetcher = null;
    private null|(DirectSendStrategy&MockObject) $directSendStrategy = null;
    private null|(LoggerInterface&MockObject) $logger = null;
    private ?IngestionSendStrategy $strategy = null;

    protected function setUp(): void
    {
        $this->configHelper = $this->createMock(IngestionConfigHelper::class);

        $this->ingestionClient = $this->createMock(IngestionClient::class);
        $this->clientProvider = $this->createMock(IngestionClientProviderInterface::class);
        $this->clientProvider->method('getClient')->willReturn($this->ingestionClient);

        $this->taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $this->indexNameFetcher = $this->createMock(IndexNameFetcher::class);
        $this->directSendStrategy = $this->createMock(DirectSendStrategy::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->strategy = new IngestionSendStrategy(
            $this->configHelper,
            $this->clientProvider,
            $this->taskService,
            $this->indexNameFetcher,
            $this->directSendStrategy,
            $this->logger
        );
    }

    // --- Feature flag gating ---

    public function testIsApplicableReturnsFalseWhenDisabled(): void
    {
        $this->configHelper->method('isEnabled')->with(self::STORE_ID)->willReturn(false);

        $this->assertFalse($this->strategy->isApplicable(self::STORE_ID));
    }

    public function testIsApplicableReturnsTrueWhenEnabled(): void
    {
        $this->configHelper->method('isEnabled')->with(self::STORE_ID)->willReturn(true);

        $this->assertTrue($this->strategy->isApplicable(self::STORE_ID));
    }

    // --- Normal index: pushTask ---

    public function testSendUsesPushTaskForNormalIndex(): void
    {
        $indexOptions = $this->mockIndexOptions(self::INDEX_NAME, false);
        $this->taskService->method('getTaskId')
            ->with($indexOptions)
            ->willReturn(self::TASK_ID);

        $expectedPayload = ['action' => 'addObject', 'records' => [['objectID' => '1']]];
        $this->ingestionClient->expects($this->once())
            ->method('pushTask')
            ->with(self::TASK_ID, $expectedPayload)
            ->willReturn(['taskID' => '123']);

        $this->ingestionClient->expects($this->never())->method('push');

        $result = $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
        ]);

        $this->assertSame(['taskID' => '123'], $result);
    }

    // --- Action grouping ---

    public function testSendGroupsRequestsByAction(): void
    {
        $indexOptions = $this->mockIndexOptions(self::INDEX_NAME, false);
        $this->taskService->method('getTaskId')->willReturn(self::TASK_ID);

        $pushTaskCalls = [];
        $this->ingestionClient->method('pushTask')
            ->willReturnCallback(function ($taskId, $payload) use (&$pushTaskCalls) {
                $pushTaskCalls[] = $payload;
                return ['taskID' => '123'];
            });

        $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
            ['action' => 'addObject', 'body' => ['objectID' => '2']],
            ['action' => 'deleteObject', 'body' => ['objectID' => '3']],
        ]);

        $this->assertCount(2, $pushTaskCalls);
        $this->assertSame('addObject', $pushTaskCalls[0]['action']);
        $this->assertCount(2, $pushTaskCalls[0]['records']);
        $this->assertSame('deleteObject', $pushTaskCalls[1]['action']);
        $this->assertCount(1, $pushTaskCalls[1]['records']);
    }

    // --- Temp index: push with referenceIndexName ---

    public function testSendUsesPushWithReferenceForTempIndex(): void
    {
        $indexOptions = $this->mockIndexOptions(self::TMP_INDEX_NAME, true);
        $this->indexNameFetcher->method('getOriginalIndexName')
            ->with(self::TMP_INDEX_NAME)
            ->willReturn(self::INDEX_NAME);

        $expectedPayload = ['action' => 'addObject', 'records' => [['objectID' => '1']]];
        $this->ingestionClient->expects($this->once())
            ->method('push')
            ->with(self::TMP_INDEX_NAME, $expectedPayload, true, self::INDEX_NAME)
            ->willReturn(['taskID' => '456']);

        $this->taskService->expects($this->never())->method('getTaskId');
        $this->ingestionClient->expects($this->never())->method('pushTask');

        $result = $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
        ]);

        $this->assertSame(['taskID' => '456'], $result);
    }

    public function testSendStripsTmpSuffixForReferenceIndex(): void
    {
        $tmpName = 'magento2_store_categories_tmp';
        $prodName = 'magento2_store_categories';
        $indexOptions = $this->mockIndexOptions($tmpName, true);
        $this->indexNameFetcher->method('getOriginalIndexName')
            ->with($tmpName)
            ->willReturn($prodName);

        $this->ingestionClient->expects($this->once())
            ->method('push')
            ->with(
                $tmpName,
                $this->anything(),
                true,
                $prodName
            )
            ->willReturn([]);

        $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
        ]);
    }

    // --- Fallback on error ---

    public function testSendFallsBackOnErrorWhenFallbackEnabled(): void
    {
        $indexOptions = $this->mockIndexOptions(self::INDEX_NAME, false);
        $this->taskService->method('getTaskId')->willReturn(self::TASK_ID);

        $this->ingestionClient->method('pushTask')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->configHelper->method('isFallbackEnabled')->willReturn(true);

        $requests = [['action' => 'addObject', 'body' => ['objectID' => '1']]];
        $this->directSendStrategy->expects($this->once())
            ->method('send')
            ->with($indexOptions, $requests)
            ->willReturn(['taskID' => 'batch-123']);

        $result = $this->strategy->send($indexOptions, $requests);
        $this->assertSame(['taskID' => 'batch-123'], $result);
    }

    public function testSendThrowsOnErrorWhenFallbackDisabled(): void
    {
        $indexOptions = $this->mockIndexOptions(self::INDEX_NAME, false);
        $this->taskService->method('getTaskId')->willReturn(self::TASK_ID);

        $this->ingestionClient->method('pushTask')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->configHelper->method('isFallbackEnabled')->willReturn(false);
        $this->directSendStrategy->expects($this->never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection failed');

        $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
        ]);
    }

    // --- Multi-task ambiguity ---

    public function testSendLogsWarningOnMultiTaskAmbiguity(): void
    {
        $indexOptions = $this->mockIndexOptions(self::TMP_INDEX_NAME, true);
        $this->indexNameFetcher->method('getOriginalIndexName')
            ->willReturn(self::INDEX_NAME);

        $this->ingestionClient->method('push')->willThrowException(
            new BadRequestException(
                'multiple tasks (task-1, task-2, etc) found for the Push connector with indexName '
                . self::TMP_INDEX_NAME
                . ', please use /2/tasks/:id/push instead',
                400
            )
        );

        $this->configHelper->method('isFallbackEnabled')->willReturn(true);
        $this->directSendStrategy->method('send')->willReturn([]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Ingestion push failed due to multi-task ambiguity',
                $this->callback(fn($ctx) => $ctx['indexName'] === self::TMP_INDEX_NAME)
            );

        $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
        ]);
    }

    public function testSendFallsBackOnMultiTaskAmbiguity(): void
    {
        $indexOptions = $this->mockIndexOptions(self::TMP_INDEX_NAME, true);
        $this->indexNameFetcher->method('getOriginalIndexName')
            ->willReturn(self::INDEX_NAME);

        $this->ingestionClient->method('push')
            ->willThrowException(new BadRequestException('Found zero task for this index', 400));

        $this->configHelper->method('isFallbackEnabled')->willReturn(true);

        $requests = [['action' => 'addObject', 'body' => ['objectID' => '1']]];
        $this->directSendStrategy->expects($this->once())
            ->method('send')
            ->with($indexOptions, $requests)
            ->willReturn(['taskID' => 'fallback']);

        $result = $this->strategy->send($indexOptions, $requests);
        $this->assertSame(['taskID' => 'fallback'], $result);
    }

    // --- Stale task 404 retry ---

    public function testSendRetriesOnceOnStaleTask404(): void
    {
        $indexOptions = $this->mockIndexOptions(self::INDEX_NAME, false);

        $newTaskId = 'new-task-uuid';
        $this->taskService->expects($this->exactly(2))
            ->method('getTaskId')
            ->willReturnOnConsecutiveCalls(self::TASK_ID, $newTaskId);

        $this->taskService->expects($this->once())
            ->method('invalidate')
            ->with($indexOptions);

        $this->ingestionClient->expects($this->exactly(2))
            ->method('pushTask')
            ->willReturnCallback(function ($taskId) {
                if ($taskId === self::TASK_ID) {
                    throw new NotFoundException('Task not found', 404);
                }
                return ['taskID' => '789'];
            });

        $result = $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
        ]);

        $this->assertSame(['taskID' => '789'], $result);
    }

    public function testSendDoesNotRetryFor404OnTempIndex(): void
    {
        $indexOptions = $this->mockIndexOptions(self::TMP_INDEX_NAME, true);
        $this->indexNameFetcher->method('getOriginalIndexName')
            ->willReturn(self::INDEX_NAME);

        $this->ingestionClient->method('push')
            ->willThrowException(new NotFoundException('Not found', 404));

        $this->configHelper->method('isFallbackEnabled')->willReturn(true);
        $this->taskService->expects($this->never())->method('invalidate');

        $this->directSendStrategy->expects($this->once())
            ->method('send')
            ->willReturn(['taskID' => 'fallback']);

        $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
        ]);
    }

    // --- Return value ---

    public function testSendReturnsLastActionGroupResponse(): void
    {
        $indexOptions = $this->mockIndexOptions(self::INDEX_NAME, false);
        $this->taskService->method('getTaskId')->willReturn(self::TASK_ID);

        $callCount = 0;
        $this->ingestionClient->method('pushTask')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return ['taskID' => 'response-' . $callCount];
            });

        $result = $this->strategy->send($indexOptions, [
            ['action' => 'addObject', 'body' => ['objectID' => '1']],
            ['action' => 'deleteObject', 'body' => ['objectID' => '2']],
        ]);

        $this->assertSame(['taskID' => 'response-2'], $result);
    }

    // --- Helpers ---

    private function mockIndexOptions(string $indexName, bool $isTmp): IndexOptionsInterface&MockObject
    {
        $mock = $this->createMock(IndexOptionsInterface::class);
        $mock->method('getStoreId')->willReturn(self::STORE_ID);
        $mock->method('getIndexName')->willReturn($indexName);
        $mock->method('isTemporaryIndex')->willReturn($isTmp);
        return $mock;
    }
}
