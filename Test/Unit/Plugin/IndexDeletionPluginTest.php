<?php

namespace Algolia\Ingestion\Test\Unit\Plugin;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Service\AlgoliaConnector;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Plugin\IndexDeletionPlugin;
use PHPUnit\Framework\MockObject\MockObject;

class IndexDeletionPluginTest extends TestCase
{
    private null|(IngestionTaskServiceInterface&MockObject) $taskService = null;
    private ?IndexDeletionPlugin $plugin = null;

    protected function setUp(): void
    {
        $this->taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $this->plugin = new IndexDeletionPlugin($this->taskService);
    }

    public function testAfterDeleteIndexCallsInvalidateWithIndexOptions(): void
    {
        /** @var IndexOptionsInterface&MockObject $indexOptions */
        $indexOptions = $this->createMock(IndexOptionsInterface::class);
        /** @var AlgoliaConnector&MockObject $connector */
        $connector = $this->createMock(AlgoliaConnector::class);

        $this->taskService->expects($this->once())
            ->method('invalidate')
            ->with($this->identicalTo($indexOptions));

        $this->plugin->afterDeleteIndex($connector, null, $indexOptions);
    }
}
