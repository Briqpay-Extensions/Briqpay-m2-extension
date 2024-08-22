<?php

namespace Briqpay\Payments\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;

class SetupConfig
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var ResolverInterface
     */
    protected $localeResolver;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * SetupConfig constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ResolverInterface $localeResolver
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ResolverInterface $localeResolver,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->localeResolver = $localeResolver;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Get setup configuration for payment module.
     *
     * @return array
     */
    public function getSetupConfig(): array
    {
        $locale = $this->localeResolver->getLocale();
        $countryCode = $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_STORE);
        $currencyCode = $this->storeManager->getStore()->getCurrentCurrencyCode();
        $redirectUrl = $this->urlBuilder->getUrl('checkout/order/success');
        $hooksUrl = $this->urlBuilder->getUrl('briqpay/webhooks');
        $customerType = $this->scopeConfig->getValue(
            'payment/briqpay/checkout_type',
            ScopeInterface::SCOPE_STORE
        );
        $termsPageIdentifier = $this->scopeConfig->getValue(
            'payment/briqpay/terms_conditions_page',
            ScopeInterface::SCOPE_STORE
        );

        // Generate the full URL for the CMS page
        $termsUrl = $this->urlBuilder->getUrl(null, ['_direct' => $termsPageIdentifier]);

        $setupConfig = [
            'locale' => str_replace('_', '-', $locale),
            'country' => $countryCode,
            'currency' => $currencyCode,
            'redirect_url' => $redirectUrl,
            "terms_url" => $termsUrl,
            'hooks_url' => $hooksUrl,
            'customer_type' => $customerType
        ];

        return $setupConfig;
    }
}