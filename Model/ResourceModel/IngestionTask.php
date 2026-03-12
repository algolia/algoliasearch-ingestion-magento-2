<?php
/**
 * Copyright © Algolia, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Algolia\Ingestion\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IngestionTask extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('algoliasearch_ingestion_task', 'id');
    }
}
