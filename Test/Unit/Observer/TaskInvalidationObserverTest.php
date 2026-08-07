<?php

declare(strict_types=1);

namespace Algolia\Ingestion\Test\Unit\Observer;

use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Observer\TaskInvalidationObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;

class TaskInvalidationObserverTest extends TestCase
{
    private const WATCHED_PATH = 'algoliasearch_credentials/credentials/api_key';
    private const OTHER_WATCHED_PATH = 'algoliasearch_credentials/credentials/application_id';
    private const UNWATCHED_PATH = 'algoliasearch_credentials/credentials/debug';

    protected function createObjectToTest(
        ?IngestionTaskServiceInterface $taskService = null,
        ?StoreManagerInterface $storeManager = null,
        ?array $watchedPaths = null,
    ): TaskInvalidationObserver {
        return new TaskInvalidationObserver(
            $taskService ?? $this->createStub(IngestionTaskServiceInterface::class),
            $storeManager ?? $this->createStub(StoreManagerInterface::class),
            $watchedPaths ?? [self::WATCHED_PATH, self::OTHER_WATCHED_PATH],
        );
    }

    // --- Store scope ---

    public function testExecuteInvalidatesSpecificStore(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '1',
            'website' => '',
            'changed_paths' => [self::WATCHED_PATH],
        ]);

        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->once())
            ->method('invalidateByStore')
            ->with(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->never())->method('getWebsite');
        $storeManager->expects($this->never())->method('getStores');

        $this->createObjectToTest(taskService: $taskService, storeManager: $storeManager)
            ->execute($magentoObserver);
    }

    // --- Website scope ---

    public function testExecuteInvalidatesWebsiteStoresOnWebsiteScope(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '',
            'website' => '1',
            'changed_paths' => [self::WATCHED_PATH],
        ]);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())
            ->method('getStores')
            ->willReturn([
                $this->mockStore(1, 1),
                $this->mockStore(2, 1),
                $this->mockStore(3, 2),
            ]);

        $invalidated = [];
        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->exactly(2))
            ->method('invalidateByStore')
            ->willReturnCallback(function (int $storeId) use (&$invalidated): void {
                $invalidated[] = $storeId;
            });

        $this->createObjectToTest(taskService: $taskService, storeManager: $storeManager)
            ->execute($magentoObserver);

        $this->assertSame([1, 2], $invalidated);
    }

    // --- Default scope ---

    public function testExecuteInvalidatesAllStoresOnDefaultScope(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '',
            'website' => '',
            'changed_paths' => [self::WATCHED_PATH],
        ]);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())
            ->method('getStores')
            ->willReturn([
                $this->mockStore(1, 1),
                $this->mockStore(2, 2),
            ]);

        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->exactly(2))
            ->method('invalidateByStore');

        $this->createObjectToTest(taskService: $taskService, storeManager: $storeManager)
            ->execute($magentoObserver);
    }

    // --- changed_paths filtering ---

    public function testSkipsWhenChangedPathsMissing(): void
    {
        $magentoObserver = $this->mockObserver(['store' => '1', 'website' => '']);

        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->never())->method('invalidateByStore');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->never())->method('getStores');

        $this->createObjectToTest(taskService: $taskService, storeManager: $storeManager)
            ->execute($magentoObserver);
    }

    public function testSkipsWhenNoWatchedPathChanged(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '1',
            'website' => '',
            'changed_paths' => [self::UNWATCHED_PATH],
        ]);

        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->never())->method('invalidateByStore');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->never())->method('getStores');

        $this->createObjectToTest(taskService: $taskService, storeManager: $storeManager)
            ->execute($magentoObserver);
    }

    public function testInvalidatesWhenAnyWatchedPathPresent(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '1',
            'website' => '',
            'changed_paths' => [
                self::UNWATCHED_PATH,
                self::OTHER_WATCHED_PATH,
            ],
        ]);

        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->once())
            ->method('invalidateByStore')
            ->with(1);

        $this->createObjectToTest(taskService: $taskService)->execute($magentoObserver);
    }

    public function testEmptyWatchedPathsAlwaysSkips(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '1',
            'website' => '',
            'changed_paths' => [self::WATCHED_PATH],
        ]);

        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->never())->method('invalidateByStore');

        $this->createObjectToTest(taskService: $taskService, watchedPaths: [])
            ->execute($magentoObserver);
    }

    // --- Helpers ---

    private function mockObserver(array $eventData): Observer&Stub
    {
        $event = new Event($eventData);

        $observer = $this->createStub(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    private function mockStore(int $id, int $websiteId): StoreInterface&Stub
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getWebsiteId')->willReturn($websiteId);
        return $store;
    }
}
