<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ScopeHelper
{
    protected $storeManager;
    protected $scopeConfig;

    /**
     * The store whose settings apply instead of the request's, see inStore().
     *
     * @var int|null
     */
    private $storeId = null;

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
    ) {
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Runs $operation with the settings of an order's or quote's store.
     *
     * An admin invoice, credit memo or cancel, and a webhook, run in another store than
     * the order's (the admin store, or the default store view of a shared domain), so
     * credentials and test mode would otherwise come from the wrong website.
     *
     * @param int|string|null $storeId
     * @param callable $operation
     * @return mixed what $operation returns
     */
    public function inStore($storeId, callable $operation)
    {
        $previous = $this->storeId;
        $this->storeId = $storeId === null || $storeId === '' ? $previous : (int) $storeId;
        try {
            return $operation();
        } finally {
            $this->storeId = $previous;
        }
    }

    public function getScopedConfigValue(string $path): ?string
    {
        // Try store scope
        $storeId = $this->storeManager->getStore($this->storeId)->getId();
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value !== null && $value !== '') {
            return $value;
        }

        // Try website scope
        $websiteId = $this->storeManager->getStore($this->storeId)->getWebsiteId();
        $website = $this->storeManager->getWebsite($websiteId);
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $website->getCode());
        if ($value !== null && $value !== '') {
            return $value;
        }

        // Fallback to default scope
        return $this->scopeConfig->getValue($path);
    }
}
