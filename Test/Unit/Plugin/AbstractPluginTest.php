<?php

declare(strict_types=1);

namespace Loqate\ApiIntegration\Test\Unit\Plugin;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Concrete subclass exposing AbstractPlugin's protected helpers for testing.
 */
class ConcreteAbstractPlugin extends AbstractPlugin
{
    public function callShouldVerify($field, $value): bool
    {
        return $this->shouldVerify($field, $value);
    }

    public function callValidateEmail($email)
    {
        return $this->validateEmail($email);
    }

    public function callValidatePhone($phone, $country = null)
    {
        return $this->validatePhone($phone, $country);
    }
}

/**
 * Tests for the shared validation logic in AbstractPlugin, which every frontend
 * and admin validation plugin extends.
 */
class AbstractPluginTest extends TestCase
{
    /** @var Validator&MockObject */
    private $validator;

    /** @var Data&MockObject */
    private $helper;

    /** @var Session */
    private $session;

    /** @var ConcreteAbstractPlugin */
    private $plugin;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(Validator::class);
        $this->helper = $this->createMock(Data::class);

        // Stateful in-memory session double so the de-dupe logic can be exercised.
        $this->session = new class extends Session {
            /** @var array<string, mixed> */
            private array $store = [];

            public function getData($key = '', $clear = false)
            {
                return $this->store[$key] ?? null;
            }

            public function setData($key, $value = null)
            {
                $this->store[$key] = $value;
                return $this;
            }
        };

        $this->plugin = new ConcreteAbstractPlugin(
            $this->createMock(Context::class),
            $this->createMock(UrlInterface::class),
            $this->session,
            $this->validator,
            $this->helper,
            $this->createMock(JsonFactory::class)
        );
    }

    public function testShouldVerifyTracksValuesPerField(): void
    {
        $this->assertTrue($this->plugin->callShouldVerify('loqate_email', 'a@b.com'), 'first time must verify');
        $this->assertFalse($this->plugin->callShouldVerify('loqate_email', 'a@b.com'), 'repeat must skip');
        $this->assertTrue($this->plugin->callShouldVerify('loqate_email', 'c@d.com'), 'new value must verify');
        $this->assertTrue($this->plugin->callShouldVerify('loqate_phone', 'a@b.com'), 'different field is independent');
    }

    public function testValidateEmailSkipsRepeatSubmissionWhenSubmitNotPrevented(): void
    {
        $this->helper->method('getConfigValue')->willReturn(false); // prevent_submit off
        $this->validator->expects($this->once())
            ->method('verifyEmail')
            ->willReturn(['valid' => true]);

        $this->assertFalse($this->plugin->callValidateEmail('a@b.com'));
        // Second submission of the same email must not hit the API again.
        $this->assertFalse($this->plugin->callValidateEmail('a@b.com'));
    }

    public function testValidateEmailReturnsUnexpectedErrorPhraseOnVerifyError(): void
    {
        $this->helper->method('getConfigValue')->willReturn(false);
        $this->validator->method('verifyEmail')->willReturn(['error' => true, 'message' => 'boom']);

        $result = $this->plugin->callValidateEmail('x@y.com');

        $this->assertStringContainsString('unexpected error', (string)$result);
        $this->assertStringContainsString('email', (string)$result);
    }

    public function testValidateEmailReturnsResubmitMessageWhenResponseEmpty(): void
    {
        $this->helper->method('getConfigValue')->willReturn(false);
        $this->validator->method('verifyEmail')->willReturn([]);

        $result = $this->plugin->callValidateEmail('x@y.com');

        $this->assertStringContainsString('Submit again', (string)$result);
    }

    public function testValidateEmailReturnsFalseWhenNoApiKey(): void
    {
        $this->helper->method('getConfigValue')->willReturn(false);
        $this->validator->method('verifyEmail')->willReturn(['noKeyFound' => true]);

        $this->assertFalse($this->plugin->callValidateEmail('x@y.com'));
    }

    public function testValidatePhoneReturnsUnexpectedErrorPhraseOnVerifyError(): void
    {
        $this->helper->method('getConfigValue')->willReturn(false);
        $this->validator->method('verifyPhoneNumber')->willReturn(['error' => true, 'message' => 'boom']);

        $result = $this->plugin->callValidatePhone('+441234567890', 'GB');

        $this->assertStringContainsString('unexpected error', (string)$result);
        $this->assertStringContainsString('phone number', (string)$result);
    }
}
