<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ScopeHelper
{
    protected $storeManager;
    protected $scopeConfig;

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
    ) {
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
    }
    public function getScopedConfigValue(string $path): ?string
    {
        // Try store scope
        $storeId = $this->storeManager->getStore()->getId();
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value !== null && $value !== '') {
            return $value;
        }

        // Try website scope
        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        $website = $this->storeManager->getWebsite($websiteId);
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $website->getCode());
        if ($value !== null && $value !== '') {
            return $value;
        }

        // Fallback to default scope
        return $this->scopeConfig->getValue($path);
    }
}
