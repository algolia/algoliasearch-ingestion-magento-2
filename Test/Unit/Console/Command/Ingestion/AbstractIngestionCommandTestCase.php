<?php

namespace Algolia\Ingestion\Test\Unit\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

abstract class AbstractIngestionCommandTestCase extends TestCase
{
    protected null|(State&MockObject) $state = null;
    protected null|(StoreManagerInterface&MockObject) $storeManager = null;
    protected null|(StoreNameFetcher&MockObject) $storeNameFetcher = null;
    protected null|(IngestionConfigHelper&MockObject) $ingestionConfigHelper = null;

    protected function setUp(): void
    {
        $this->state                 = $this->createMock(State::class);
        $this->storeManager          = $this->createMock(StoreManagerInterface::class);
        $this->storeNameFetcher      = $this->createMock(StoreNameFetcher::class);
        $this->ingestionConfigHelper = $this->createMock(IngestionConfigHelper::class);

        $this->storeNameFetcher->method('getStoreName')
            ->willReturnCallback(fn(int $id) => "Store $id");
    }

    /**
     * Build a mock StoreManagerInterface::getStores() return shape: store_id keyed array
     * of StoreInterface mocks. IngestionInitCommand uses `array_keys()` on this.
     *
     * @param int[] $storeIds
     * @return array<int, StoreInterface&MockObject>
     */
    protected function mockStoresKeyedById(array $storeIds): array
    {
        $out = [];
        foreach ($storeIds as $id) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $out[$id] = $store;
        }
        return $out;
    }
}
