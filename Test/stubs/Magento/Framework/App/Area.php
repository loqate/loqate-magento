<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Only the area-code constants are stubbed, which is all
 * Observer\QuoteSubmitBefore::isAdminArea() uses. The values match Magento's, and the
 * three non-admin ones are listed because they are the areas the observer MUST keep
 * running in: 'graphql' (Hyvä checkout) and 'webapi_rest' (Luma checkout) do not
 * inherit 'frontend', which is why the registration in etc/events.xml stays global.
 */

namespace Magento\Framework\App;

if (!class_exists(\Magento\Framework\App\Area::class, false)) {
    class Area
    {
        const AREA_GLOBAL = 'global';
        const AREA_FRONTEND = 'frontend';
        const AREA_ADMINHTML = 'adminhtml';
        const AREA_WEBAPI_REST = 'webapi_rest';
        const AREA_GRAPHQL = 'graphql';
    }
}
