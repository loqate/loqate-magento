<?php

namespace Loqate\ApiIntegration\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Data
 */
class Data extends AbstractHelper
{
    /** @var StoreManagerInterface */
    protected $storeManager;

    /**
     * Data constructor
     *
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Context $context, StoreManagerInterface $storeManager)
    {
        parent::__construct($context);
        $this->storeManager = $storeManager;
    }

    /**
     * Get current website ID
     *
     * @return int
     */
    public function getCurrentWebsite()
    {
        try {
            return $this->storeManager->getStore()->getWebsiteId();
        } catch (NoSuchEntityException $e) {
            return 0;
        }
    }

    /**
     * Get current store view ID
     *
     * Narrower than getCurrentWebsite(): getConfigValue() reads at SCOPE_STORE, so
     * anything cached against a config-dependent verdict has to be scoped per store
     * view, not per website.
     *
     * @return int
     */
    public function getCurrentStore(): int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException $e) {
            return 0;
        }
    }

    /**
     * Get config value for website
     *
     * @param $configPath
     * @return mixed
     */
    public function getConfigValueForWebsite($configPath)
    {
        return $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_WEBSITE, $this->getCurrentWebsite());
    }

    /**
     * Get config value
     *
     * @param $configPath
     * @return mixed
     */
    public function getConfigValue($configPath)
    {
        return $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get config value at default scope, ignoring any website or store view value
     *
     * For settings that are global by design, so a website or store row (set via CLI,
     * config.php/env.php or left in the database) cannot override what the admin shows.
     *
     * @param $configPath
     * @return mixed
     */
    public function getDefaultConfigValue($configPath)
    {
        return $this->scopeConfig->getValue($configPath, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /**
     * Get config value as a NAMED store view resolves it.
     *
     * getConfigValue() reads SCOPE_STORE with no store, which resolves to whatever store is
     * current - and in the admin area that is the default store, so a per-store-view override
     * is invisible to it. Anything that has to reason about the configuration of stores OTHER
     * than the current one has to name the store, which is what this is for.
     *
     * @param $configPath
     * @param $storeId
     * @return mixed
     */
    public function getConfigValueForStore($configPath, $storeId)
    {
        return $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
