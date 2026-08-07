<?php

declare(strict_types=1);

namespace Algolia\Ingestion\Test\Unit\Plugin;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Service\AlgoliaConnector;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;
use Algolia\Ingestion\Plugin\IndexDeletionPlugin;

class IndexDeletionPluginTest extends TestCase
{
    protected function createObjectToTest(
        ?IngestionTaskServiceInterface $taskService = null,
    ): IndexDeletionPlugin {
        return new IndexDeletionPlugin(
            $taskService ?? $this->createStub(IngestionTaskServiceInterface::class),
        );
    }

    public function testAfterDeleteIndexCallsInvalidateByIndexWithIndexOptions(): void
    {
        $indexOptions = $this->createStub(IndexOptionsInterface::class);
        $connector = $this->createStub(AlgoliaConnector::class);

        $taskService = $this->createMock(IngestionTaskServiceInterface::class);
        $taskService->expects($this->once())
            ->method('invalidateByIndex')
            ->with($this->identicalTo($indexOptions));

        $this->createObjectToTest(taskService: $taskService)
            ->afterDeleteIndex($connector, null, $indexOptions);
    }
}
