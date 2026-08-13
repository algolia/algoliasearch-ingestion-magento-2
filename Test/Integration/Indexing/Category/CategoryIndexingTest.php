<?php

namespace Algolia\Ingestion\Test\Integration\Indexing\Category;

use Algolia\AlgoliaSearch\Service\Category\BatchQueueProcessor as CategoryBatchQueueProcessor;
use Algolia\Ingestion\Test\Integration\Indexing\IngestionIndexingTestCase;

class CategoryIndexingTest extends IngestionIndexingTestCase
{
    public function testCategoryIndexing(): void
    {
        $taskID = $this->initEntityTask('categories');
        $this->applyTransformation($taskID);

        $categoryBatchQueueProcessor = $this->objectManager->get(CategoryBatchQueueProcessor::class);
        $this->processTest(
            $categoryBatchQueueProcessor,
            'categories',
            $this->assertValues->expectedCategory
        );

        $this->assertTransformationIsApplied('categories');
    }

    public function testWithCustomTransformation(): void
    {
        $taskID = $this->initEntityTask('categories');

        $transformation = 'async function transform(record, helper) {
  record[\'path\'] +=  \' (custom)\';
  return record;
  }';

        $this->applyTransformation($taskID, $transformation);

        $categoryBatchQueueProcessor = $this->objectManager->get(CategoryBatchQueueProcessor::class);
        $this->processTest(
            $categoryBatchQueueProcessor,
            'categories',
            $this->assertValues->expectedCategory
        );

        $this->assertTransformationIsApplied('categories', 'path', '(custom)');
    }

    public function testMalformedTransformation(): void
    {
        $taskID = $this->initEntityTask('categories');
        $this->applyTransformation($taskID, 'malformed');

        $categoryBatchQueueProcessor = $this->objectManager->get(CategoryBatchQueueProcessor::class);
        $this->processTest(
            $categoryBatchQueueProcessor,
            'categories',
            $this->assertValues->expectedCategory
        );
    }
}
