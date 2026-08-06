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
 * IT DOES SHIP TO PRODUCTION, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT. composer.json
 * maps "Loqate\\ApiIntegration\\": "" under the PRODUCTION autoload section - the module root is
 * the PSR-4 root, which is how a Magento module is laid out - so this class is autoloadable in a
 * live install even though the Test\ prefix is also mapped under autoload-dev. Recorded here
 * because a public MUTABLE static that can switch the contact digests off is exactly the kind of
 * thing that should be found on purpose and not by surprise.
 *
 * WHY IT IS ACCEPTABLE, in the order the questions get asked:
 *  - NOTHING IN PRODUCTION CAN REACH THE FLAG'S EFFECT. The flag is inert on its own: the only
 *    reader is the bare function in Test/Support/CsprngOverride.php, which Composer's PSR-4
 *    cannot autoload (it is a function, not a class) and which only Test/bootstrap.php requires.
 *    A live install never loads that file, so setting Csprng::$unavailable there changes nothing
 *    at all.
 *  - AND IF IT COULD, IT FAILS CLOSED. The behaviour it simulates is a salt that cannot be
 *    minted, which makes contactDigest() return its "do not cache this" sentinel: every email
 *    address and phone number is then verified on the Loqate API and NOTHING is stored. The cost
 *    is extra billable verifies - never a bypass, never a weaker digest, and never a raw value in
 *    the session. That is the same direction the class chooses for a real CSPRNG failure, which
 *    is the whole point of the test this switch exists for.
 *  - THE ALTERNATIVE DOES NOT ACTUALLY REMOVE IT. A Magento module is deployed as a directory, so
 *    the file is on disk in a live install whatever composer.json says; narrowing the production
 *    autoload would only stop the class being resolvable BY NAME, and it cannot be narrowed to
 *    "everything except Test/" without giving up the single PSR-4 root a Magento module is
 *    expected to have. The gain would be nil and the layout would stop being conventional.
 * So in production the flag exists, nothing sets it, nothing reads it, and the worst case if
 * something ever did is a larger Loqate invoice. Revisit this if Test/Support ever grows a class
 * whose flipped state would LOOSEN a check rather than tighten one - that one would not ship.
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
