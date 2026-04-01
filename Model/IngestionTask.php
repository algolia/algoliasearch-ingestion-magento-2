<?php

namespace Algolia\Ingestion\Model;

use Magento\Framework\Model\AbstractModel;

class IngestionTask extends AbstractModel
{
    protected $_eventPrefix = 'algoliasearch_ingestion_task';
    protected $_eventObject = 'ingestion_task';

    protected function _construct()
    {
        $this->_init(\Algolia\Ingestion\Model\ResourceModel\IngestionTask::class);
    }
}
