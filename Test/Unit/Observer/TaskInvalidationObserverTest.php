<?php

namespace Algolia\Ingestion\Test\Unit\Observer;

use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Observer\TaskInvalidationObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class TaskInvalidationObserverTest extends TestCase
{
    private const WATCHED_PATH = 'algoliasearch_credentials/credentials/api_key';
    private const OTHER_WATCHED_PATH = 'algoliasearch_credentials/credentials/application_id';
    private const UNWATCHED_PATH = 'algoliasearch_credentials/credentials/debug';

    private null|(IngestionTaskServiceInterface&MockObject) $taskService = null;
    private null|(StoreManagerInterface&MockObject) $storeManager = null;
    private ?TaskInvalidationObserver $observer = null;

    protected function setUp(): void
    {
        $this->taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->observer = new TaskInvalidationObserver(
            $this->taskService,
            $this->storeManager,
            [self::WATCHED_PATH, self::OTHER_WATCHED_PATH]
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

        $this->taskService->expects($this->once())
            ->method('invalidateByStoreId')
            ->with(1);

        $this->storeManager->expects($this->never())->method('getWebsite');
        $this->storeManager->expects($this->never())->method('getStores');

        $this->observer->execute($magentoObserver);
    }

    // --- Website scope ---

    public function testExecuteInvalidatesWebsiteStoresOnWebsiteScope(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '',
            'website' => '1',
            'changed_paths' => [self::WATCHED_PATH],
        ]);

        $this->storeManager->expects($this->once())
            ->method('getStores')
            ->willReturn([
                $this->mockStore(1, 1),
                $this->mockStore(2, 1),
                $this->mockStore(3, 2),
            ]);

        $invalidated = [];
        $this->taskService->expects($this->exactly(2))
            ->method('invalidateByStoreId')
            ->willReturnCallback(function (int $storeId) use (&$invalidated): void {
                $invalidated[] = $storeId;
            });

        $this->observer->execute($magentoObserver);

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

        $this->storeManager->expects($this->once())
            ->method('getStores')
            ->willReturn([
                $this->mockStore(1, 1),
                $this->mockStore(2, 2),
            ]);

        $this->taskService->expects($this->exactly(2))
            ->method('invalidateByStoreId');

        $this->observer->execute($magentoObserver);
    }

    // --- changed_paths filtering ---

    public function testSkipsWhenChangedPathsMissing(): void
    {
        $magentoObserver = $this->mockObserver(['store' => '1', 'website' => '']);

        $this->taskService->expects($this->never())->method('invalidateByStoreId');
        $this->storeManager->expects($this->never())->method('getStores');

        $this->observer->execute($magentoObserver);
    }

    public function testSkipsWhenNoWatchedPathChanged(): void
    {
        $magentoObserver = $this->mockObserver([
            'store' => '1',
            'website' => '',
            'changed_paths' => [self::UNWATCHED_PATH],
        ]);

        $this->taskService->expects($this->never())->method('invalidateByStoreId');
        $this->storeManager->expects($this->never())->method('getStores');

        $this->observer->execute($magentoObserver);
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

        $this->taskService->expects($this->once())
            ->method('invalidateByStoreId')
            ->with(1);

        $this->observer->execute($magentoObserver);
    }

    public function testEmptyWatchedPathsAlwaysSkips(): void
    {
        $observer = new TaskInvalidationObserver(
            $this->taskService,
            $this->storeManager,
            []
        );

        $magentoObserver = $this->mockObserver([
            'store' => '1',
            'website' => '',
            'changed_paths' => [self::WATCHED_PATH],
        ]);

        $this->taskService->expects($this->never())->method('invalidateByStoreId');

        $observer->execute($magentoObserver);
    }

    // --- Helpers ---

    private function mockObserver(array $eventData): Observer&MockObject
    {
        $event = new Event($eventData);

        /** @var Observer&MockObject $observer */
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    private function mockStore(int $id, int $websiteId): StoreInterface&MockObject
    {
        /** @var StoreInterface&MockObject $store */
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getWebsiteId')->willReturn($websiteId);
        return $store;
    }
}
