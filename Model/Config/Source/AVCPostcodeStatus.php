<?php

namespace Loqate\ApiIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * AVCPostcodeStatus class
 */
class AVCPostcodeStatus implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'P8', 'label' => __('PostalCodePrimary and PostalCodeSecondary verified')],
            ['value' => 'P7', 'label' => __('PostalCodePrimary verified, PostalCodeSecondary added or changed')],
            ['value' => 'P6', 'label' => __('PostalCodePrimary verified')],
            ['value' => 'P5', 'label' => __('PostalCodePrimary verified with small change')],
            ['value' => 'P4', 'label' => __('PostalCodePrimary verified with large change')],
            ['value' => 'P3', 'label' => __('PostalCodePrimary added')],
            ['value' => 'P2', 'label' => __('PostalCodePrimary identified by lexicon')],
            ['value' => 'P1', 'label' => __('PostalCodePrimary identified by context')],
            ['value' => 'P0', 'label' => __('PostalCodePrimary empty')],
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
            ['P8' => __('PostalCodePrimary and PostalCodeSecondary verified')],
            ['P7' => __('PostalCodePrimary verified, PostalCodeSecondary added or changed')],
            ['P6' => __('PostalCodePrimary verified')],
            ['P5' => __('PostalCodePrimary verified with small change')],
            ['P4' => __('PostalCodePrimary verified with large change')],
            ['P3' => __('PostalCodePrimary added')],
            ['P2' => __('PostalCodePrimary identified by lexicon')],
            ['P1' => __('PostalCodePrimary identified by context')],
            ['P0' => __('PostalCodePrimary empty')],
        ];
    }
}
