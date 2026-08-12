<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Only the behaviour constants are stubbed: Plugin\Admin\ValidateImportAddress compares
 * the subject's behaviour against BEHAVIOR_ADD_UPDATE and does nothing else with this
 * class. The values match Magento's, so a test that sets the wrong behaviour really does
 * take the "not our behaviour" branch.
 */

namespace Magento\ImportExport\Model;

if (!class_exists(\Magento\ImportExport\Model\Import::class, false)) {
    class Import
    {
        const BEHAVIOR_APPEND = 'append';
        const BEHAVIOR_ADD_UPDATE = 'add_update';
        const BEHAVIOR_REPLACE = 'replace';
        const BEHAVIOR_DELETE = 'delete';
        const BEHAVIOR_CUSTOM = 'custom';
    }
}
