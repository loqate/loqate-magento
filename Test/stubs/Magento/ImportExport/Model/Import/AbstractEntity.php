<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Only the error code Plugin\Admin\ValidateImportAddress reports with is stubbed; the value
 * matches Magento's, so a test can assert the code the merchant's import report is grouped
 * by rather than a placeholder.
 */

namespace Magento\ImportExport\Model\Import;

if (!class_exists(\Magento\ImportExport\Model\Import\AbstractEntity::class, false)) {
    abstract class AbstractEntity
    {
        const ERROR_CODE_SYSTEM_EXCEPTION = 'systemException';
    }
}
