<?php

/**
 * A random_bytes() inside the module's Helper namespace, so a test can make the platform's
 * CSPRNG appear to be missing.
 *
 * PHP resolves an UNQUALIFIED call to a function - which is what
 * Helper\ShopperScopedSessionStores::resolveContactDigestSalt() makes - by looking in the
 * current namespace first and only then in the global one. Declaring this function therefore
 * intercepts that one call site without the class under test knowing anything about it: it is
 * still constructed through its real constructor and still calls random_bytes() itself. See
 * Test\Support\Csprng for WHY that branch has to be reachable at all - in short, it is the only
 * way to observe the fail-CLOSED behaviour, and the fail-OPEN alternative silently produces a
 * digest that is identical in every session on every installation.
 *
 * NO CLASS IS DECLARED HERE, deliberately: a class in namespace Loqate\ApiIntegration\Helper
 * belongs in Helper/ under this module's PSR-4 root, so putting one here would be a violation
 * that Composer could not autoload and that Magento's DI compiler would have to be argued with.
 * A bare function is invisible to both. It is required explicitly from Test/bootstrap.php rather
 * than autoloaded, because Composer's PSR-4 does not autoload functions and because something
 * that shadows a core function for the whole suite should be visible in the bootstrap rather
 * than arriving as a side effect of loading a test.
 *
 * OFF BY DEFAULT AND RESTORED IN A FINALLY: with Csprng::$unavailable false - its state for
 * every test but the two that flip it - this delegates to the real \random_bytes(), so the
 * production code runs against the real CSPRNG exactly as it does in a browser.
 *
 * TWO THINGS TO KNOW BEFORE ADDING A SECOND CSPRNG CALL TO Helper\.
 *  - THE SHADOW IS NAMESPACE-WIDE AND RUN-WIDE. A function cannot be declared for one class,
 *    so this intercepts every unqualified random_bytes() in Loqate\ApiIntegration\Helper for
 *    the whole suite, not just resolveContactDigestSalt()'s. Today there is exactly one such
 *    call. A second one added later would be silently test-doubled along with it - harmless
 *    while the switch is off, and something to remember when a test that flips the switch
 *    starts failing for a reason that looks unrelated. Narrow this to a specific caller if
 *    that ever becomes confusing; a \random_bytes() at the new call site opts out of it
 *    entirely.
 *  - THE EXCEPTION CLASS IS NOT WHAT IS PINNED. This throws a plain \Exception, while PHP
 *    8.2+ answers a missing CSPRNG with \Random\RandomException. Production catches
 *    \Throwable, so both are caught identically and the test cannot pass for the wrong
 *    reason - but a test that narrowed that catch would need this to throw the real class,
 *    and \Random\RandomException cannot be constructed here without assuming the platform
 *    that is being simulated away. What is pinned is that the throw is NOT swallowed into an
 *    empty salt, which is the direction that matters.
 */

declare(strict_types=1);

namespace Loqate\ApiIntegration\Helper;

use Loqate\ApiIntegration\Test\Support\Csprng;

if (!function_exists(__NAMESPACE__ . '\random_bytes')) {
    /**
     * @param int $length Bytes of CSPRNG output requested.
     * @return string
     * @throws \Exception When the test switch says the platform has no usable CSPRNG. PHP's own
     *                    random_bytes() answers that with \Random\RandomException on 8.2+, which
     *                    extends \Exception; production catches \Throwable, so the exact class
     *                    is not what is being pinned - that it is NOT swallowed into an empty
     *                    salt is.
     */
    function random_bytes(int $length): string
    {
        if (Csprng::$unavailable) {
            throw new \Exception('Test double: this host has no usable CSPRNG.');
        }

        return \random_bytes($length);
    }
}
