<?php

namespace Algolia\Ingestion\Test\Integration\Indexing\Product;

use Algolia\AlgoliaSearch\Exceptions\BadRequestException;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Algolia\AlgoliaSearch\Service\Product\BatchQueueProcessor as ProductBatchQueueProcessor;
use Algolia\Ingestion\Test\Integration\Indexing\IngestionIndexingTestCase;
use Magento\CatalogInventory\Model\StockRegistry;
use Magento\Framework\Exception\NoSuchEntityException;

class ProductIndexingTest extends IngestionIndexingTestCase
{
    public const OUT_OF_STOCK_PRODUCT_SKU = '24-MB01';

    protected ?StockRegistry $stockRegistry = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockRegistry = $this->objectManager->get(StockRegistry::class);

        $this->setConfig(ConfigHelper::SHOW_OUT_OF_STOCK, 0);
        $this->updateStockItem(self::OUT_OF_STOCK_PRODUCT_SKU, false);
    }

    public function testProductIndexing(): void
    {
        $taskID = $this->initEntityTask('products');
        $this->applyTransformation($taskID);

        $productBatchQueueProcessor = $this->objectManager->get(ProductBatchQueueProcessor::class);
        $this->processTest(
            $productBatchQueueProcessor,
            'products',
            $this->assertValues->productsOnStockCount
        );

        $this->assertTransformationIsApplied('products');
    }

    public function testWithCustomTransformation(): void
    {
        $taskID = $this->initEntityTask('products');

        $transformation = 'async function transform(record, helper) {
  record[\'sku\'] +=  \' (custom)\';
  return record;
  }';

        $this->applyTransformation($taskID, $transformation);

        $productBatchQueueProcessor = $this->objectManager->get(ProductBatchQueueProcessor::class);
        $this->processTest(
            $productBatchQueueProcessor,
            'products',
            $this->assertValues->productsOnStockCount
        );

        $this->assertTransformationIsApplied('products', 'sku', '(custom)');
    }

    public function testMalformedTransformation(): void
    {
        $taskID = $this->initEntityTask('products');
        $this->applyTransformation($taskID, 'malformed');

        $productBatchQueueProcessor = $this->objectManager->get(ProductBatchQueueProcessor::class);
        $this->processTest(
            $productBatchQueueProcessor,
            'products',
            $this->assertValues->productsOnStockCount
        );

        $this->assertTransformationIsNotApplied('products');
    }

    public function testMalformedTransformationWithoutFallbackMode(): void
    {
        $taskID = $this->initEntityTask('products');
        $this->applyTransformation($taskID, 'malformed');

        // Disabling the fallback mode
        $this->setConfig('algoliasearch_indexing_manager/ingestion/fallback_to_batch', 0);

        $productBatchQueueProcessor = $this->objectManager->get(ProductBatchQueueProcessor::class);

        try {
            $this->processTest(
                $productBatchQueueProcessor,
                'products',
                $this->assertValues->productsOnStockCount
            );
        } catch (BadRequestException $e) {
            // Assserting the Algolia API returns the expected error
            $this->assertEquals(400, $e->getCode());
            $this->assertStringContainsString("malformed is not defined", $e->getMessage());
        }

        $this->setConfig('algoliasearch_indexing_manager/ingestion/fallback_to_batch', 1);
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
