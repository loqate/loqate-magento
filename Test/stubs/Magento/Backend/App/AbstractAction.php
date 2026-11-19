<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Parent of the Backend\App\Action stub, needed so the admin Capture controllers can load.
 * Keeps the two members those controllers' guarantees are about: ADMIN_RESOURCE, which a
 * controller overrides to choose its ACL resource, and _isAllowed(), which reads it. The real
 * class reaches ActionInterface through Framework\App\Action\Action; this stub does not, because
 * the Magento/Framework stubs load after Magento/Backend and the controllers only need
 * HttpGetActionInterface.
 */

namespace Magento\Backend\App;

if (!class_exists(\Magento\Backend\App\AbstractAction::class, false)) {
    abstract class AbstractAction
    {
        public const ADMIN_RESOURCE = 'Magento_Backend::admin';

        /**
         * @param Action\Context $context
         */
        public function __construct(Action\Context $context)
        {
        }

        /**
         * @return bool
         */
        protected function _isAllowed()
        {
            return false;
        }
    }
}
