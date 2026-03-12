<?php

namespace Algolia\Ingestion\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IngestionTask extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('algoliasearch_ingestion_task', 'id');
    }
}
