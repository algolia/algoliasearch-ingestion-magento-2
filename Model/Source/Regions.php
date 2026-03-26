<?php

namespace Algolia\Ingestion\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Regions implements OptionSourceInterface
{
    public const REGION_US = "us";
    public const REGION_EU = "eu";

    public function toOptionArray()
    {
        return [
            [
                'value' => self::REGION_US,
                'label' => __('America (US)'),
            ],
            [
                'value' => self::REGION_EU,
                'label' => __('Europe (EU)'),
            ],
        ];
    }
}
