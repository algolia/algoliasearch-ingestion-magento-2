<?php

namespace Algolia\Ingestion\Model\Cleanup\Plan;

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

    /**
     * True when this plan is a DELETE with a non-null id, i.e. the executor has
     * something concrete to act on. A DELETE with a null id is a placeholder
     * left by buildPlan when the local row had no recorded id; nothing to delete.
     */
    public function canDelete(): bool
    {
        return $this->isDelete() && $this->id !== null;
    }
}
