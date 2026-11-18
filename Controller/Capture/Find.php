<?php

namespace Loqate\ApiIntegration\Controller\Capture;

use Loqate\ApiIntegration\Helper\Controller;
use Loqate\ApiIntegration\Helper\StorefrontCaptureGate;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Find class
 */
class Find implements ActionInterface
{
    /** @var Controller $controllerHelper */
    private $controllerHelper;

    /** @var StorefrontCaptureGate $gate */
    private $gate;

    /**
     * Find constructor
     *
     * @param Controller $controllerHelper
     * @param StorefrontCaptureGate $gate
     */
    public function __construct(Controller $controllerHelper, StorefrontCaptureGate $gate)
    {
        $this->controllerHelper = $controllerHelper;
        $this->gate = $gate;
    }

    /**
     * Call capture find API endpoint using PHP library, unless the storefront gate refuses it
     *
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $refusal = $this->gate->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        return $this->controllerHelper->find();
    }
}
