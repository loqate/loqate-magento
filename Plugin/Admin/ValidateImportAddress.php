<?php

namespace Loqate\ApiIntegration\Plugin\Admin;

use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Magento\CustomerImportExport\Model\Import\Address;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;

/**
 * Class ValidateImportAddress
 */
class ValidateImportAddress extends AbstractPlugin
{
    /**
     * Check if addresses are valid for batch import
     *
     * Validator::verifyMultipleAddresses() has THREE return shapes and all three have to be
     * handled here before anything is merged:
     *  - array<int, bool>, keys 0..N-1 per chunk - the normal case;
     *  - false, when the billable API call failed OR answered a row count that cannot be
     *    attributed to the addresses sent (see the count guard in that method: the connector's
     *    array_column() drops unanswerable records and reindexes the rest, so a partial
     *    response is indistinguishable from a shifted one and neither may be merged);
     *  - ['noKeyFound' => true], when no API key is configured.
     * Passing either non-verdict shape to array_merge() is what made a mid-import API
     * failure a HARD CRASH: array_merge($allRowsResult, false) is a TypeError in PHP 8, and
     * the catch (\Exception) below does NOT catch it - TypeError is an \Error, not an
     * \Exception - so the import died with a 500 instead of degrading. 'noKeyFound' merges
     * cleanly but is worse in its own way: a string key in row-indexed data, reported to the
     * merchant as "Invalid address at row #1" because ($index + 1) of the string key 'noKeyFound' is 1.
     *
     * @param Address $subject
     * @param $result
     * @return void
     */
    public function afterValidateData(Address $subject, $result)
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return $result;
        }

        if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_customer_import')
        && $subject->getBehavior() == Import::BEHAVIOR_ADD_UPDATE) {
            try {
                $source = $subject->getSource();
                if ($source) {
                    $sourceArray = iterator_to_array($source);
                    $batches = array_chunk($sourceArray, 100);
                    $allRowsResult = [];
                    foreach ($batches as $batch) {
                        $batchResult = $this->validator->verifyMultipleAddresses($batch, false);

                        if ($batchResult === false) {
                            // The billable API call failed for this chunk, so we hold no
                            // verdict for any of its rows. Reported ONCE, as a critical
                            // error with no row number, and verification stops: continuing
                            // would either pass unverified rows silently or attribute a
                            // transport failure to individual rows the merchant would then
                            // "fix". Failing the validation rather than letting the import
                            // through is the same fail-closed stance the module already
                            // takes on this kind of failure in admin order create
                            // (Plugin\Admin\OrderSave.php:59-64) and at checkout.
                            $result->addError(
                                AbstractEntity::ERROR_CODE_SYSTEM_EXCEPTION,
                                ProcessingError::ERROR_LEVEL_CRITICAL,
                                null,
                                null,
                                __('Addresses could not be validated: the Loqate service did not respond. '
                                    . 'Please try the import again.')
                            );

                            return $result;
                        }

                        if (!is_array($batchResult) || isset($batchResult['noKeyFound'])) {
                            // No API key (or any other non-verdict shape a future change
                            // might add): there is nothing to validate in THIS chunk, so
                            // verification stops here. Never merged - a 'noKeyFound' key in
                            // row-indexed data is reported as row #1.
                            //
                            // break, NOT return: unlike the false branch above, this branch
                            // adds no error of its own, so the import proceeds either way -
                            // and returning here would throw away $allRowsResult, silently
                            // discarding every genuinely invalid row already found in earlier
                            // chunks and importing it. Breaking keeps those verdicts and lets
                            // the reporting loop below run on them. Reaching this on a LATER
                            // chunk means the key was removed mid-import, in which case the
                            // rows verified before that is strictly better than nothing.
                            break;
                        }

                        $allRowsResult = array_merge($allRowsResult, $batchResult);
                    }

                    // Row numbering is CORRECT as written and must not be "fixed": each
                    // chunk returns keys 0..N-1 in input order (guaranteed by
                    // Validator::verifyMultipleAddresses(), which pre-seeds its result in
                    // input key order for exactly this reason), so array_merge() renumbering
                    // by insertion order yields 0..149 across chunks of 100 and ($index + 1)
                    // is the import row number by construction. A chunk can no longer come back
                    // SHORT either - a row count that does not match the addresses sent returns
                    // false and is handled above - so the one case that could renumber later
                    // chunks is now unreachable rather than merely improbable.
                    //check for invalid addresses
                    foreach ($allRowsResult as $index => $validAddress) {
                        if (!$validAddress) {
                            $result->addError(
                                AbstractEntity::ERROR_CODE_SYSTEM_EXCEPTION,
                                ProcessingError::ERROR_LEVEL_CRITICAL,
                                $index + 1,
                                null,
                                __('Invalid address at row #') . ($index + 1)
                            );
                        }
                    }
                }
            } catch (\InvalidArgumentException $exception) {
                // Deliberately NOT swallowed. On this path an \InvalidArgumentException can
                // only be ShopperScopedAddressStores::assertEnrolled() reporting that a
                // session store was reached without being enrolled in the shopper-ownership
                // flush - a programming error a developer has to fix, not a runtime failure
                // to absorb.
                //
                // It cannot be a deserialisation failure: the serializer forwards to
                // json_decode() and every call site that unserialises a cache entry
                // (Validator::getCachedVerifyResult(), getCachedBatchVerifyResult(),
                // checkForCapturedAddress() and Controller::storeCapturedAddress()) already
                // catches \InvalidArgumentException itself and degrades to a cache miss, so
                // a malformed entry never reaches this far.
                //
                // This plugin has no logger - its base class serves ten plugins and does not
                // carry one - so swallowing here would leave no trace anywhere and silently
                // defeat the one assertion that exists to catch an unguarded store. Letting
                // it out is the only way it reaches anybody.
                throw $exception;
            } catch (\Exception $exception) {
                // Everything else is a runtime failure - transport, connector, source file -
                // and must not hard-fail the merchant's import. Rows already checked keep
                // their verdicts; the rest go unverified, which the chunk handling above
                // reports to the merchant.
                return $result;
            }
        }

        return $result;
    }
}
