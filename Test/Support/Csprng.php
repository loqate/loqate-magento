<?php

namespace Loqate\ApiIntegration\Test\Support;

/**
 * The switch that makes the platform's CSPRNG appear to be missing, for the one guarantee that
 * cannot be observed any other way.
 *
 * WHY THIS EXISTS. Helper\ShopperScopedSessionStores mints its contact-digest salt with
 * random_bytes(), and the branch that matters is the one where random_bytes() THROWS: the class
 * then returns '' rather than a salt, contactDigest() propagates '' as its "do not cache this"
 * sentinel, and Plugin\AbstractPlugin::shouldVerify() answers that by performing the billable
 * verify and storing nothing. That is the fail-CLOSED direction, and the whole of the reduction
 * rests on it - the alternative implementation, hashing under an empty key, produces a
 * perfectly well-formed 64-character digest that is IDENTICAL in every session on every
 * installation, which is precisely the global identifier for an email address the salt exists
 * to prevent, and it grants bypasses on top. Nothing else in the class can be made to return an
 * empty salt: a salt of the wrong SHAPE is re-minted successfully (that path has its own test),
 * so the CSPRNG failing is the only way in.
 *
 * HOW IT WORKS, and why it is deterministic rather than clever. Test/Support/CsprngOverride.php
 * declares a random_bytes() function inside the namespace the class under test lives in, and
 * PHP resolves an unqualified call to a function in the CURRENT namespace before the global
 * one. That override delegates to the real \random_bytes() unless this switch is on, so with
 * the switch off - which is its state for every other test in the suite, guaranteed by the
 * finally in failing() - the production code runs against the real CSPRNG exactly as it does in
 * a browser.
 *
 * WHAT IT IS NOT. It is not a mock of the class under test and it does not reach inside it: the
 * class is still built through its real constructor and still calls random_bytes() itself. The
 * only thing replaced is the PLATFORM capability, which is the thing the guarantee is about.
 *
 * IT DOES SHIP, and that is worth knowing rather than fixing here: composer.json maps
 * "Loqate\\ApiIntegration\\": "" under the production autoload section (the module root is the
 * PSR-4 root, which is how a Magento module is laid out), so this class is autoloadable in a
 * live install even though the Test\ prefix is also mapped under autoload-dev. Nothing calls it
 * there - it is one public static bool and one static method, no constructor, no state that
 * outlives a call - and the FUNCTION that reads it (Test/Support/CsprngOverride.php) is a bare
 * function that Composer's PSR-4 cannot autoload at all and that only Test/bootstrap.php
 * requires. So in production the flag exists, nothing sets it, and nothing reads it.
 */
final class Csprng
{
    /**
     * Is the platform CSPRNG to be reported as unavailable?
     *
     * Read by the override in Test/Support/CsprngOverride.php on every call. Public because
     * that file is a plain function in another namespace and has nothing to be a friend of;
     * flipped only through failing(), which restores it.
     *
     * @var bool
     */
    public static $unavailable = false;

    /**
     * Run $body on a host whose CSPRNG has failed, and restore the platform afterwards.
     *
     * The restore is in a finally so that an assertion failure inside $body - which throws -
     * cannot leave the switch on and turn one failing test into a cascade of unrelated ones.
     * That is the difference between a deterministic suite and a haunted one.
     *
     * @param callable $body What to exercise while the CSPRNG is unavailable.
     * @return mixed Whatever $body returned.
     */
    public static function failing(callable $body)
    {
        self::$unavailable = true;
        try {
            return $body();
        } finally {
            self::$unavailable = false;
        }
    }
}
