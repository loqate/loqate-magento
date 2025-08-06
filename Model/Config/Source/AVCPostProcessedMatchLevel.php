<?php

namespace Loqate\ApiIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * AVCPostProcessedMatchLevel class
 */
class AVCPostProcessedMatchLevel implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 5, 'label' => __('Delivery Point (PostBox or SubBuilding)')],
            ['value' => 4, 'label' => __('Premise (Premise or Building)')],
            ['value' => 3, 'label' => __('Thoroughfare')],
            ['value' => 2, 'label' => __('Locality or PostalCode')],
            ['value' => 1, 'label' => __('AdministrativeArea')],
            ['value' => 0, 'label' => __('None')],
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [
            ['5' => __('Delivery Point (PostBox or SubBuilding)')],
            ['4' => __('Premise (Premise or Building)')],
            ['3' => __('Thoroughfare')],
            ['2' => __('Locality or PostalCode')],
            ['1' => __('AdministrativeArea')],
            ['0' => __('None')],
        ];
    }
}
