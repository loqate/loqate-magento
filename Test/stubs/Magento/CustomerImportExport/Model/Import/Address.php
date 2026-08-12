<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Plugin\Admin\ValidateImportAddress::afterValidateData() declares this as its $subject
 * type, so the class has to exist for the plugin to be callable at all, and it calls
 * exactly the two accessors stubbed here.
 *
 * Deliberately does NOT extend Magento\ImportExport\Model\Import\AbstractEntity, which the
 * real class reaches through AbstractCustomer: the stubs are loaded in sorted path order
 * (Test/bootstrap.php), which puts this file before AbstractEntity's, so an extends clause
 * would be resolved before its parent existed. Both accessors are declared on AbstractEntity
 * in the real code, which changes nothing for a test double.
 */

namespace Magento\CustomerImportExport\Model\Import;

if (!class_exists(\Magento\CustomerImportExport\Model\Import\Address::class, false)) {
    class Address
    {
        /**
         * Import behaviour of the current run, e.g. Import::BEHAVIOR_ADD_UPDATE.
         *
         * @return string
         */
        public function getBehavior()
        {
            return '';
        }

        /**
         * The import source, an iterator over the file's rows.
         *
         * Declared without a return type exactly like the real accessor, whose
         * AbstractSource return is only documented, so a test double can hand back any
         * iterator - which is all iterator_to_array() in the plugin needs.
         *
         * @return \Iterator|null
         */
        public function getSource()
        {
            return null;
        }
    }
}
