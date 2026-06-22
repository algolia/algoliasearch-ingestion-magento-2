<?php

namespace Algolia\Ingestion\Model\Cleanup\Result;

use Algolia\Ingestion\Model\Cleanup\Plan\RowPlan;

class RowResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly RowPlan $plan,
        public readonly string $status,
        public readonly int $deletedCount,
        public readonly int $preservedCount,
        public readonly ?string $failedOnObject = null,
        public readonly ?string $failureMessage = null
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
