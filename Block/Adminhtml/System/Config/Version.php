<?php

namespace Loqate\ApiIntegration\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Class Version
 * Displays the module version in system configuration
 */
class Version extends Field
{
    /**
     * @var ModuleListInterface
     */
    protected $moduleList;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param ModuleListInterface $moduleList
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        ModuleListInterface $moduleList,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->moduleList = $moduleList;
    }

    /**
     * Render version field
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $moduleName = 'Loqate_ApiIntegration';
        $moduleInfo = $this->moduleList->getOne($moduleName);
        $version = $moduleInfo['setup_version'] ?? 'Unknown';

        $html = '<div style="padding: 10px 0;">';
        $html .= '<strong style="font-size: 14px;">Loqate Integration Version: ' . $this->escapeHtml($version) . '</strong>';
        $html .= '</div>';

        return $html;
    }
}

