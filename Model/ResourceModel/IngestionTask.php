<?php

namespace Algolia\Ingestion\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IngestionTask extends AbstractDb
{
    const TABLE_NAME = 'algoliasearch_ingestion_task';
    const ID = 'id';

    protected function _construct()
    {
        $this->_init(self::TABLE_NAME, self::ID);
    }
}
