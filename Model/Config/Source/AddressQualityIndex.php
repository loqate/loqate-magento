<?php

namespace Loqate\ApiIntegration\Model\Config\Source;

use Loqate\ApiIntegration\Helper\Validator;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source for the "Minimum Address Quality Index" admin field
 * (loqate_settings/address_settings/address_quality_index).
 *
 * WHY THE VALUES ARE DERIVED AND NOT WRITTEN OUT (LOQ-17148). The option VALUES come from
 * Helper\Validator::VALID_QUALITY_INDEXES - the list Validator::checkQualityIndex() requires the
 * configured threshold to be in - and only the LABELS live here. This class used to spell A-E
 * out itself, which was a second copy of that list, free to drift in either direction, and both
 * directions are merchant-facing defects:
 *  - a value offered here that the verifier does not accept makes Validator::
 *    readableQualityIndexThreshold() refuse to judge at all, so EVERY address is rejected. The
 *    merchant sets a quality bar and watches every import row come back "Invalid address at row
 *    #N", with only a log line - which merchants do not read - saying the value they chose was
 *    the problem;
 *  - a value the verifier accepts but this class does not offer is a threshold only a data
 *    patch, direct SQL or bin/magento config:set can select - which is the state the setting
 *    was in before LOQ-17148, when etc/adminhtml/system.xml exposed no field for it.
 * Deriving one list from the other makes both impossible by construction rather than merely
 * unlikely. Test\Unit\Model\Config\Source\AddressQualityIndexTest asserts the correspondence in
 * both directions anyway, because "derived" is only true until somebody edits it.
 *
 * A SELECT, NOT A TEXT FIELD, for the same reason: checkQualityIndex() compares the threshold
 * with <=, a STRING comparison, so free text sorting above 'E' ('zzz', a lowercase 'c', a 'C+')
 * would pass EVERY address - a silent total bypass of the merchant's own quality bar - and text
 * sorting below 'A' would reject every one. A select over this list cannot hold either.
 */
class AddressQualityIndex implements OptionSourceInterface
{
    /**
     * Human label per quality index, in the Cleansing API's grade order (best to worst).
     *
     * The merchant chooses a quality bar, not a letter out of a response format, so the letters
     * are never offered bare. Keyed by grade rather than listed in order because the ORDER and
     * the SET both come from Validator::VALID_QUALITY_INDEXES; this is a lookup, not a
     * second list. A grade added to that constant without a label here degrades to showing the
     * grade itself (see optionLabel()), which keeps every accepted threshold selectable - the
     * invariant that matters - and shows up as an unlabelled option rather than as a missing one.
     */
    private const LABELS = [
        'A' => 'Excellent',
        'B' => 'Good',
        'C' => 'Average',
        'D' => 'Poor',
        'E' => 'Bad',
    ];

    /**
     * Options getter
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        $options = [];
        foreach (Validator::VALID_QUALITY_INDEXES as $qualityIndex) {
            $options[] = ['value' => $qualityIndex, 'label' => $this->optionLabel($qualityIndex)];
        }

        return $options;
    }

    /**
     * Get options as a LIST of single-entry [grade => label] maps.
     *
     * NOT a grade-keyed lookup, despite the name Magento's own source models give this method:
     * the outer array is a 0..N-1 list, so toArray()[$grade] finds nothing. The shape is
     * deliberately identical to the module's sibling sources (Model\Config\Source\*), because
     * anything that iterates them iterates this one too; do not "fix" it here alone.
     *
     * @return array<int, array<string, \Magento\Framework\Phrase>>
     */
    public function toArray()
    {
        $options = [];
        foreach (Validator::VALID_QUALITY_INDEXES as $qualityIndex) {
            $options[] = [$qualityIndex => $this->optionLabel($qualityIndex)];
        }

        return $options;
    }

    /**
     * The label one quality index is offered under.
     *
     * Falls back to the grade itself for a grade with no label yet, so that adding a value to
     * Validator::VALID_QUALITY_INDEXES can never make it UNSELECTABLE - an unlabelled option is
     * a cosmetic defect, an unreachable threshold is the defect this class exists to prevent.
     *
     * @param string $qualityIndex One value of Validator::VALID_QUALITY_INDEXES.
     * @return \Magento\Framework\Phrase
     */
    private function optionLabel(string $qualityIndex)
    {
        return __(self::LABELS[$qualityIndex] ?? $qualityIndex);
    }
}
