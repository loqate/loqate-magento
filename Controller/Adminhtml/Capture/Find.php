<?php

namespace Loqate\ApiIntegration\Controller\Adminhtml\Capture;

use Loqate\ApiIntegration\Helper\Controller;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Find class
 *
 * Extends the backend Action so Magento requires an admin login before execute() runs.
 */
class Find extends Action implements HttpGetActionInterface
{
    /**
     * Any logged-in admin: the lookup serves the order-create, order-address-edit and
     * customer-edit pages, whose users may lack configuration rights.
     */
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /** @var Controller $controllerHelper */
    private $controllerHelper;

    /**
     * Find constructor
     *
     * @param Context $context
     * @param Controller $controllerHelper
     */
    public function __construct(Context $context, Controller $controllerHelper)
    {
        parent::__construct($context);
        $this->controllerHelper = $controllerHelper;
    }

    /**
     * Call capture find API endpoint using PHP library
     *
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        return $this->controllerHelper->find();
    }
}
