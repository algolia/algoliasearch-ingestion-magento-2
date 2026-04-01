<?php

namespace Algolia\Ingestion\Model\ResourceModel\IngestionTask;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'id';
    protected $_eventPrefix = 'algoliasearch_ingestion_task_collection';
    protected $_eventObject = 'ingestion_task_collection';

    protected function _construct()
    {
        $this->_init(
            \Algolia\Ingestion\Model\IngestionTask::class,
            \Algolia\Ingestion\Model\ResourceModel\IngestionTask::class
        );
    }
}
