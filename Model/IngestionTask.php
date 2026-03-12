<?php
/**
 * Copyright © Algolia, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Algolia\Ingestion\Model;

use Magento\Framework\Model\AbstractModel;

class IngestionTask extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Algolia\Ingestion\Model\ResourceModel\IngestionTask::class);
    }
}
