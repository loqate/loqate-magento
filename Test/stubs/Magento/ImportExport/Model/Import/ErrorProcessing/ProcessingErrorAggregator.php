<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * This is the object Address::validateData() returns and that
 * Plugin\Admin\ValidateImportAddress::afterValidateData() both receives as $result and adds
 * its errors to. addError() keeps the real signature - and, in particular, the real PARAMETER
 * ORDER - because the plugin passes the row number positionally as the third argument: a
 * stub that reordered them would make a mis-attributed row number look correct.
 */

namespace Magento\ImportExport\Model\Import\ErrorProcessing;

if (!class_exists(\Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregator::class, false)) {
    class ProcessingErrorAggregator
    {
        /**
         * @param string $errorCode
         * @param string $errorLevel
         * @param int|null $rowNumber
         * @param string|null $columnName
         * @param string|null $errorMessage
         * @param string|null $errorDescription
         * @return $this
         */
        public function addError(
            $errorCode,
            $errorLevel = ProcessingError::ERROR_LEVEL_CRITICAL,
            $rowNumber = null,
            $columnName = null,
            $errorMessage = null,
            $errorDescription = null
        ) {
            return $this;
        }
    }
}
