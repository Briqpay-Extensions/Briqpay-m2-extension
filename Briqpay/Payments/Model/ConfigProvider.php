<?php

namespace Briqpay\Payments\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class ConfigProvider implements ConfigProviderInterface
{
    const XML_PATH_BRIQPAY_TITLE = 'payment/briqpay/checkout_title';
    const XML_PATH_BRIQPAY_DECISION = 'payment/briqpay/custom_decision';
    const XML_PATH_ENABLE_TERMS_AND_CONDITIONS = 'checkout/options/enable_agreements';
    
    protected $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    public function getConfig()
    {
        $config = [];
        $config['payment']['briqpay'] = [
            'title' => $this->getBriqpayTitle(),
            'terms_conditions_enabled' => $this->isTermsAndConditionsEnabled(),
            'customDecisionLogic' => $this->customDecisionLogic(),
            // Add other configuration options here if needed
        ];
        return $config;
    }

    protected function getBriqpayTitle()
    {
        return $this->scopeConfig->getValue(self::XML_PATH_BRIQPAY_TITLE, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
    protected function isTermsAndConditionsEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_TERMS_AND_CONDITIONS, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
    protected function customDecisionLogic()
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BRIQPAY_DECISION, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        return $value === '1'; // Convert "1" to true, otherwise false
    }
}
