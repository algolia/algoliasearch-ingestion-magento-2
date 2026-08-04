<?php

namespace Algolia\Ingestion\Test\Integration\Indexing\Page;

use Algolia\AlgoliaSearch\Service\Page\BatchQueueProcessor as PageBatchQueueProcessor;
use Algolia\Ingestion\Test\Integration\Indexing\IngestionIndexingTestCase;

class PageIndexingTest extends IngestionIndexingTestCase
{
    public function testPageIndexing(): void
    {
        $this->initEntityTask('_pages');

        $pageBatchQueueProcessor = $this->objectManager->get(PageBatchQueueProcessor::class);
        $this->processTest($pageBatchQueueProcessor, 'pages', $this->assertValues->expectedPages);
    }
}
