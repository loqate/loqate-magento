<?php

namespace Loqate\ApiIntegration\Test\Support;

use Magento\Framework\Serialize\SerializerInterface;

/**
 * The ONE SerializerInterface double for the whole suite, failing the way the PRODUCTION
 * serializer fails.
 *
 * WHY IT IS SHARED RATHER THAN COPIED. Four harnesses drive code that reads a serialised
 * session payload back, and the double each of them installs decides whether the recovery
 * paths in that code are reachable at all. Written out per file, they drifted: two mirrored
 * the production failure mode and two were the lenient `fn ($v) => json_decode($v, true)`,
 * which silently makes every catch block unreachable from the harness that installs it. One
 * definition, used everywhere, is what stops that happening again.
 *
 * WHAT "THE PRODUCTION SERIALIZER" IS. etc/di.xml leaves SerializerInterface at Magento's
 * default, Magento\Framework\Serialize\Serializer\Json, whose unserialize() does NOT answer
 * null on bad input. It has two distinct failure modes and this double reproduces BOTH, because
 * the module's readers guard them separately:
 *
 *  1. \InvalidArgumentException, thrown for the values it rejects outright (false, null, the
 *     empty string) and for anything json_decode() reports through json_last_error(). This is
 *     what Helper\Validator::getCachedBatchVerifyResult(), ::getCachedVerifyResult() and
 *     ::checkForCapturedAddress(), and Helper\Controller::capturedEntrySignature(), all wrap in
 *     `try { ... } catch (\InvalidArgumentException $e)`. Against a lenient double NONE of those
 *     catch blocks can ever run, so deleting one would leave the suite green while a truncated
 *     or half-migrated session payload became a fatal in the middle of a checkout, an import or
 *     an admin order save.
 *
 *  2. TypeError, raised when the argument is not a string at all: json_decode()'s first
 *     parameter is declared `string $json`, so an array or object element raises an \Error,
 *     which the \InvalidArgumentException catch does NOT cover. That one is answered by the
 *     READERS' own is_string() guards, and this double must keep raising it or a reader that
 *     lost its guard would look safe here while production fatalled. Hence no cast to string
 *     below: the production serializer does not cast either.
 *
 * @see \Loqate\ApiIntegration\Test\Unit\Helper\CapturedAddressStoreTest
 * @see \Loqate\ApiIntegration\Test\Unit\Helper\ValidatorBatchVerifyCacheTest
 * @see \Loqate\ApiIntegration\Test\Unit\Helper\ValidatorImportRunDedupeTest
 * @see \Loqate\ApiIntegration\Test\Unit\Helper\ShopperScopedSessionStoresTest
 * @see \Loqate\ApiIntegration\Test\Unit\Plugin\Admin\ValidateImportAddressRowAttributionTest
 */
trait ProductionSerializerDouble
{
    /**
     * A SerializerInterface mock behaving as Magento\Framework\Serialize\Serializer\Json does.
     *
     * @return SerializerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createSerializerDouble()
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn ($value) => json_encode($value));
        $serializer->method('unserialize')->willReturnCallback(
            static function ($value) {
                // Magento\Framework\Serialize\Serializer\Json::unserialize(), verbatim in
                // behaviour: the values it rejects outright first, then a decode whose failure
                // is reported by json_last_error() rather than by a null return - null being a
                // legitimately decodable value.
                if ($value === false || $value === null || $value === '') {
                    throw new \InvalidArgumentException('Unable to unserialize value.');
                }

                // NOT cast to string first - see failure mode 2 on this trait.
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Unable to unserialize value, string is corrupted.');
                }

                return $decoded;
            }
        );

        return $serializer;
    }
}
