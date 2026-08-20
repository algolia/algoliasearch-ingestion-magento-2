<?php

namespace Algolia\Ingestion\Test\Integration\Indexing\Page;

use Algolia\AlgoliaSearch\Service\Page\BatchQueueProcessor as PageBatchQueueProcessor;
use Algolia\Ingestion\Test\Integration\Indexing\IngestionIndexingTestCase;

class PageIndexingTest extends IngestionIndexingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setConfig(
            'algoliasearch_autocomplete/autocomplete/excluded_pages',
            $this->getSerializer()->serialize([])
        );
    }

    public function testPageIndexing(): void
    {
        $taskID = $this->initEntityTask('pages');
        $this->applyTransformation($taskID);

        $pageBatchQueueProcessor = $this->objectManager->get(PageBatchQueueProcessor::class);
        $this->processTest($pageBatchQueueProcessor, 'pages', $this->assertValues->expectedPages);

        $this->assertTransformationIsApplied('pages');
    }

    public function testWithCustomTransformation(): void
    {
        $taskID = $this->initEntityTask('pages');

        $transformation = 'async function transform(record, helper) {
  record[\'slug\'] +=  \' (custom)\';
  return record;
  }';
        $this->applyTransformation($taskID, $transformation);

        $pageBatchQueueProcessor = $this->objectManager->get(PageBatchQueueProcessor::class);
        $this->processTest($pageBatchQueueProcessor, 'pages', $this->assertValues->expectedPages);

        $this->assertTransformationIsApplied('pages', 'slug', '(custom)');
    }

    public function testMalformedTransformation(): void
    {
        $taskID = $this->initEntityTask('pages');
        $this->applyTransformation($taskID, 'malformed');

        $pageBatchQueueProcessor = $this->objectManager->get(PageBatchQueueProcessor::class);
        $this->processTest($pageBatchQueueProcessor, 'pages', $this->assertValues->expectedPages);

        $this->assertTransformationIsNotApplied('pages');
    }
}
