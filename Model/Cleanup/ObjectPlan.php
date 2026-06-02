<?php

namespace Algolia\Ingestion\Model\Cleanup;

class ObjectPlan
{
    public const ACTION_DELETE = 'delete';
    public const ACTION_PRESERVE = 'preserve';

    public function __construct(
        public readonly ?string $id,
        public readonly string $action,
        public readonly string $reason = ''
    ) {}

    public static function delete(?string $id, string $reason = ''): self
    {
        return new self($id, self::ACTION_DELETE, $reason);
    }

    public static function preserve(?string $id, string $reason): self
    {
        return new self($id, self::ACTION_PRESERVE, $reason);
    }

    public function isDelete(): bool
    {
        return $this->action === self::ACTION_DELETE;
    }

    public function isPreserve(): bool
    {
        return $this->action === self::ACTION_PRESERVE;
    }
}
