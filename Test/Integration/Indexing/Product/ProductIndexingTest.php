<?php

namespace Algolia\Ingestion\Test\Integration\Indexing\Product;

use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Algolia\AlgoliaSearch\Service\Product\BatchQueueProcessor as ProductBatchQueueProcessor;
use Algolia\Ingestion\Test\Integration\Indexing\IngestionIndexingTestCase;
use Magento\CatalogInventory\Model\StockRegistry;
use Magento\Framework\Exception\NoSuchEntityException;

class ProductIndexingTest extends IngestionIndexingTestCase
{
    public const OUT_OF_STOCK_PRODUCT_SKU = '24-MB01';

    protected ?StockRegistry $stockRegistry = null;
    protected ?ProductBatchQueueProcessor $productBatchQueueProcessor = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productBatchQueueProcessor = $this->objectManager->get(ProductBatchQueueProcessor::class);
        $this->stockRegistry = $this->objectManager->get(StockRegistry::class);
    }

    public function testProductIndexing(): void
    {
        $this->initEntityTask('_products');

        $this->setConfig(ConfigHelper::SHOW_OUT_OF_STOCK, 0);
        $this->updateStockItem(self::OUT_OF_STOCK_PRODUCT_SKU, false);

        $this->processTest(
            $this->productBatchQueueProcessor,
            'products',
            $this->assertValues->productsOnStockCount
        );
    }

    /**
     * @throws NoSuchEntityException
     */
    protected function updateStockItem(string $sku, bool $isInStock): void
    {
        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        $stockItem->setIsInStock($isInStock);
        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
    }
}
