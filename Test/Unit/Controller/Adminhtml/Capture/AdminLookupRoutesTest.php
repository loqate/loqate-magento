<?php

namespace Loqate\ApiIntegration\Test\Unit\Controller\Adminhtml\Capture;

use Loqate\ApiIntegration\Controller\Adminhtml\Capture\Find;
use Loqate\ApiIntegration\Controller\Adminhtml\Capture\Retrieve;
use Loqate\ApiIntegration\Helper\Controller;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use stdClass;

/**
 * The admin address-lookup routes require an admin login (LOQ-20336).
 *
 * Magento checks the admin session and ACL only for Backend\App\AbstractAction subclasses. When
 * these controllers implemented ActionInterface directly, anyone could call
 * loqate_admin/capture/find and retrieve and make a Loqate lookup on the merchant's API key.
 */
class AdminLookupRoutesTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}>
     */
    public static function controllers(): array
    {
        return [
            'find' => [Find::class, 'find'],
            'retrieve' => [Retrieve::class, 'retrieve'],
        ];
    }

    #[DataProvider('controllers')]
    public function testExtendsBackendAction(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, Action::class));
    }

    #[DataProvider('controllers')]
    public function testAcceptsGet(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, HttpGetActionInterface::class));
    }

    #[DataProvider('controllers')]
    public function testDeclaresAdminResource(string $class): void
    {
        $constant = new ReflectionClassConstant($class, 'ADMIN_RESOURCE');

        $this->assertSame($class, $constant->getDeclaringClass()->getName());
        $this->assertSame('Magento_Backend::admin', $constant->getValue());
    }

    /**
     * An _isAllowed() override bypasses the ACL check on ADMIN_RESOURCE.
     */
    #[DataProvider('controllers')]
    public function testDoesNotOverrideIsAllowed(string $class): void
    {
        $declaringClass = (new ReflectionClass($class))->getMethod('_isAllowed')->getDeclaringClass();

        $this->assertNotSame($class, $declaringClass->getName());
    }

    #[DataProvider('controllers')]
    public function testExecuteReturnsHelperResult(string $class, string $helperMethod): void
    {
        $result = new stdClass();
        $helper = $this->createMock(Controller::class);
        $helper->expects($this->once())->method($helperMethod)->willReturn($result);

        $controller = new $class($this->createStub(Context::class), $helper);

        $this->assertSame($result, $controller->execute());
    }
}
