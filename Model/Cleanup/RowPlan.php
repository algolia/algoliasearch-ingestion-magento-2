<?php

namespace Algolia\Ingestion\Model\Cleanup;

use Algolia\Ingestion\Model\IngestionTask;

class RowPlan
{
    public const OBJECT_TASK = 'task';
    public const OBJECT_SOURCE = 'source';
    public const OBJECT_DESTINATION = 'destination';
    public const OBJECT_AUTHENTICATION = 'authentication';

    /** @var string[] In execution order. */
    public const OBJECT_TYPES = [
        self::OBJECT_TASK,
        self::OBJECT_SOURCE,
        self::OBJECT_DESTINATION,
        self::OBJECT_AUTHENTICATION,
    ];

    /**
     * @param array<string, ObjectPlan> $objects Keyed by OBJECT_* constants.
     * @param string[] $preservedTransformationIds
     */
    public function __construct(
        public readonly IngestionTask $task,
        public readonly int $storeId,
        public readonly string $indexName,
        public readonly int $origin,
        public readonly string $originLabel,
        public readonly array $objects,
        public readonly array $preservedTransformationIds = []
    ) {}

    public function getObject(string $type): ObjectPlan
    {
        return $this->objects[$type];
    }

    /** @return ObjectPlan[] */
    public function deletes(): array
    {
        return array_values(array_filter($this->objects, fn(ObjectPlan $o) => $o->isDelete() && $o->id !== null));
    }

    /** @return array<string, ObjectPlan> Keyed by OBJECT_* constants. */
    public function preserves(): array
    {
        return array_filter($this->objects, fn(ObjectPlan $o) => $o->isPreserve());
    }
}
