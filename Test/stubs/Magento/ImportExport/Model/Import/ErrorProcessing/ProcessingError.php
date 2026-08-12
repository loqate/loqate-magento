<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Only the severity levels are stubbed. Plugin\Admin\ValidateImportAddress reports at
 * ERROR_LEVEL_CRITICAL, which is the level that makes Magento refuse the import rather than
 * merely annotate it, so the distinction is load-bearing and the values match Magento's.
 */

namespace Magento\ImportExport\Model\Import\ErrorProcessing;

if (!class_exists(\Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError::class, false)) {
    class ProcessingError
    {
        const ERROR_LEVEL_CRITICAL = 'critical';
        const ERROR_LEVEL_NOT_CRITICAL = 'non-critical';
        const ERROR_LEVEL_WARNING = 'warning';
        const ERROR_LEVEL_NOTICE = 'notice';
    }
}
