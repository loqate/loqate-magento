<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Needed only because Plugin\ChangeCheckoutDefaultCountry type-hints it as the subject of its
 * after-plugin on process(). That plugin is the checkout half of the IP-country cache - the one
 * session attribute deliberately NOT enrolled in the shopper flush - and it is also the only
 * reader of the seam that runs while a PAGE is being rendered, which is why it is worth driving
 * in a test of its own rather than assuming its sibling covers it.
 */

namespace Magento\Checkout\Block\Checkout;

if (!interface_exists(\Magento\Checkout\Block\Checkout\LayoutProcessorInterface::class, false)) {
    interface LayoutProcessorInterface
    {
        /**
         * @param array $jsLayout
         * @return array
         */
        public function process($jsLayout);
    }
}
