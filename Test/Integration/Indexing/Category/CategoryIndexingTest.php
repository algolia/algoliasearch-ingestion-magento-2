<?php

namespace Algolia\Ingestion\Test\Integration\Indexing\Category;

use Algolia\AlgoliaSearch\Service\Category\BatchQueueProcessor as CategoryBatchQueueProcessor;
use Algolia\Ingestion\Test\Integration\Indexing\IngestionIndexingTestCase;

class CategoryIndexingTest extends IngestionIndexingTestCase
{
    public function testCategoryIndexing(): void
    {
        $this->initEntityTask('_categories');

        $categoryBatchQueueProcessor = $this->objectManager->get(CategoryBatchQueueProcessor::class);
        $this->processTest(
            $categoryBatchQueueProcessor,
            'categories',
            $this->assertValues->expectedCategory
        );
    }
}
