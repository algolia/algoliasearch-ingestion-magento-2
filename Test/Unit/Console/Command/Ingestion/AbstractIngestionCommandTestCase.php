<?php

namespace Algolia\Ingestion\Test\Unit\Console\Command\Ingestion;

use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

    protected function bufOut(): BufferedOutput
    {
        return new BufferedOutput();
    }

    /**
     * Build an ArrayInput with `store_id` positional args, pre-bound to the command's definition
     * so reflection-invoked execute() can call $input->getArgument() correctly.
     *
     * @param string[] $storeIds
     */
    protected function arrayInput(Command $command, array $storeIds = []): ArrayInput
    {
        $input = new ArrayInput(
            $storeIds === [] ? [] : ['store_id' => $storeIds]
        );
        $input->bind($command->getDefinition());

        return $input;
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

    /**
     * @throws \ReflectionException
     */
    protected function invokeExecute(Command $cmd, ArrayInput $input, BufferedOutput $output): int
    {
        return $this->invokeMethod($cmd, 'execute', [$input, $output]);
    }
}
