<?php

namespace Algolia\Ingestion\Plugin;

use Algolia\AlgoliaSearch\Api\Data\IndexOptionsInterface;
use Algolia\AlgoliaSearch\Service\AlgoliaConnector;
use Algolia\Ingestion\Api\IngestionTaskServiceInterface;

class IndexDeletionPlugin
{
    public function __construct(
        protected IngestionTaskServiceInterface $taskService
    ) {}

    public function afterDeleteIndex(
        AlgoliaConnector $subject,
        mixed $result,
        IndexOptionsInterface $indexOptions
    ): void {
        $this->taskService->invalidateByIndex($indexOptions);
    }
}
