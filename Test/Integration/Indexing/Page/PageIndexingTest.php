<?php

namespace Algolia\Ingestion\Test\Integration\Indexing\Page;

use Algolia\AlgoliaSearch\Exceptions\BadRequestException;
use Algolia\AlgoliaSearch\Service\Page\BatchQueueProcessor as PageBatchQueueProcessor;
use Algolia\Ingestion\Test\Integration\Indexing\IngestionIndexingTestCase;
use PHPUnit\Framework\ExpectationFailedException;

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

    public function testMalformedTransformationWithoutFallbackMode(): void
    {
        $taskID = $this->initEntityTask('pages');
        $this->applyTransformation($taskID, 'malformed');

        // Disabling the fallback mode
        $this->setConfig('algoliasearch_indexing_manager/ingestion/fallback_to_batch', 0);

        $pageBatchQueueProcessor = $this->objectManager->get(PageBatchQueueProcessor::class);
        // Since there is no fallback mode, the expected number of pages is 0
        $this->processTest($pageBatchQueueProcessor, 'pages', 0);


        $this->setConfig('algoliasearch_indexing_manager/ingestion/fallback_to_batch', 1);
    }
}
