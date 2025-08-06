<?php

namespace Loqate\ApiIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * AVCParsingStatus class
 */
class AVCParsingStatus implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'I', 'label' => __('Identified and Parsed')],
            ['value' => 'U', 'label' => __('Unable to parse')],
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
            ['I' => __('Identified and Parsed')],
            ['U' => __('Unable to parse')],
        ];
    }
}
