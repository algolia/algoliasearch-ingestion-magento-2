<?php

namespace Algolia\Ingestion\Exception;

class TaskDisabledException extends \RuntimeException
{
    public function __construct(protected string $taskId)
    {
        parent::__construct(sprintf(
            'Algolia Ingestion task %s is disabled. Re-enable in the Algolia dashboard to resume ingestion.',
            $taskId
        ));
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }
}
