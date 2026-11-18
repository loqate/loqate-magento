<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because the admin Capture controllers extend it. Empty, like the real class.
 */

namespace Magento\Backend\App;

if (!class_exists(\Magento\Backend\App\Action::class, false)) {
    abstract class Action extends AbstractAction
    {
    }
}
