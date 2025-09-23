<?php

namespace Loqate\ApiIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * AVCVerificationStatus class
 */
class AVCVerificationStatus implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'V', 'label' => __('Verified')],
            ['value' => 'P', 'label' => __('Partially Verified')],
            ['value' => 'A', 'label' => __('Ambiguous')],
            ['value' => 'R', 'label' => __('Reverted')],
            ['value' => 'U', 'label' => __('Unverified')],
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
            ['V' => __('Verified')],
            ['P' => __('Partially Verified')],
            ['A' => __('Ambiguous')],
            ['R' => __('Reverted')],
            ['U' => __('Unverified')],
        ];
    }
}
