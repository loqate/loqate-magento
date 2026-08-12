<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Plugin\AbstractPlugin type-hints this class in its constructor, so a test
 * that builds one of its ten subclasses THROUGH that constructor - which is the only way to
 * exercise the ShopperScopedSessionStores it builds inline - cannot even create a double for
 * it without the type existing.
 *
 * Declares the three accessors AbstractPlugin calls and nothing else. The real class exposes
 * more, and that permissiveness can only produce a missing test, never a false pass: a
 * subclass reaching for a method this stub omits fails loudly here.
 */

namespace Magento\Framework\App\Action;

if (!class_exists(\Magento\Framework\App\Action\Context::class, false)) {
    class Context
    {
        /**
         * @return \Magento\Framework\Message\ManagerInterface|null
         */
        public function getMessageManager()
        {
            return null;
        }

        /**
         * @return \Magento\Framework\Controller\Result\RedirectFactory|null
         */
        public function getResultRedirectFactory()
        {
            return null;
        }

        /**
         * @return \Magento\Framework\App\Response\RedirectInterface|null
         */
        public function getRedirect()
        {
            return null;
        }
    }
}
