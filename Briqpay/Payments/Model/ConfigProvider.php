<?php

namespace Briqpay\Payments\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Briqpay\Payments\Model\Utility\ScopeHelper;

class ConfigProvider implements ConfigProviderInterface
{
    const XML_PATH_ENABLE_TERMS_AND_CONDITIONS = 'checkout/options/enable_agreements';
    
    protected $scopeConfig;
    protected $scopeHelper;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ScopeHelper $scopeHelper,
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->scopeHelper = $scopeHelper;
    }

    public function getConfig()
    {
        $config = [];
        $config['payment']['briqpay'] = [
            'title' => $this->getBriqpayTitle(),
            'terms_conditions_enabled' => $this->isTermsAndConditionsEnabled(),
            'customDecisionLogic' => $this->customDecisionLogic(),
            'briqpay_overlay' => $this->briqpayOverlay()
            // Add other configuration options here if needed
        ];
        return $config;
    }

    protected function getBriqpayTitle()
    {
        return $this->scopeHelper->getScopedConfigValue('payment/briqpay/checkout_title');
    }
    protected function isTermsAndConditionsEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_TERMS_AND_CONDITIONS, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
    protected function customDecisionLogic()
    {
        $value = $this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/custom_decision');
        return $value === '1'; // Convert "1" to true, otherwise false
    }
    protected function briqpayOverlay()
    {
        $value = $this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/payment_overlay');
        return $value === '1'; // Convert "1" to true, otherwise false
    }
}
