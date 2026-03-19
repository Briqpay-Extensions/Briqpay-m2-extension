<?php
namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\RoundingHelper;
use Briqpay\Payments\Model\Utility\ScopeHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;

class CreateSession
{
    protected $setupConfig;
    protected $cart;
    protected $billingData;
    protected $shippingData;
    protected $rounding;
    protected $apiClient;
    protected $logger;
    protected $checkoutSession;
    protected $quoteRepository;
    protected $eventManager;
    protected $urlBuilder;
    private $scopeConfig;
    protected $scopeHelper;

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        GenerateCart $cart,
        AssignBillingAddress $billingData,
        AssignShippingAddress $shippingData,
        RoundingHelper $rounding,
        Logger $logger,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        ManagerInterface $eventManager,
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder,
        ScopeHelper $scopeHelper
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->cart = $cart;
        $this->billingData = $billingData;
        $this->shippingData = $shippingData;
        $this->rounding = $rounding;
        $this->logger = $logger;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->eventManager = $eventManager;
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
        $this->scopeHelper = $scopeHelper;
    }

    /**
     * Logic to build the Terms Module configuration
     *
     * @return array|null
     */
    private function getTermsModuleConfig()
    {
        $enabled = $this->scopeHelper->getScopedConfigValue(
            'payment/briqpay/advanced/enable_terms',
            ScopeInterface::SCOPE_STORE
        );

        if (!$enabled) {
            return null;
        }

        $termsJson = $this->scopeHelper->getScopedConfigValue(
            'payment/briqpay/advanced/terms_list',
            ScopeInterface::SCOPE_STORE
        );

        if (!$termsJson) {
            return null;
        }

        $termsData = json_decode($termsJson, true);
        if (!is_array($termsData) || empty($termsData)) {
            return null;
        }

        $checkboxes = [];
        foreach ($termsData as $row) {
            if (empty($row['content'])) {
                continue;
            }

            // Use the "name" field if provided, otherwise fallback to a sanitized version of the label
            $key = !empty($row['name']) ? $row['name'] : 'term_' . bin2hex(random_bytes(2));

            $checkboxes[] = [
                'key'      => $key,
                'label'    => $row['content'],
                'required' => isset($row['is_required']) && $row['is_required'] == '1',
                'default'  => isset($row['is_default']) && $row['is_default'] == '1'
            ];
        }

        return !empty($checkboxes) ? ['checkboxes' => $checkboxes] : null;
    }

    public function getPaymentModule($fallbackEmail = null)
    {
        try {
            $config = $this->setupConfig->getSetupConfig();
            $cart = $this->cart->getCart();
            $billingData = $this->billingData->getBillingData($fallbackEmail);
            $shippingData = $this->shippingData->getShippingData($fallbackEmail);
            $quoteId = $this->checkoutSession->getQuoteId();
            
            $quote = $this->quoteRepository->get($quoteId);
        } catch (\Exception $e) {
            $this->logger->error('Failed setting up config for session: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \Exception('Unable to establish connection to payment service provider.');
        }

        $uri = '/v3/session';
        $amountIncVat = $this->cart->getTotalAmount();
        $amountExVat = $this->cart->getTotalExAmount();
        $webhookBaseUrl = $this->urlBuilder->getUrl('briqpay/webhooks');

        // Initial Module Setup
        $loadModules = ['payment'];
        $modulesConfig = [
            "payment" => [
                "decision" => [
                    "enabled" => true
                ]
            ]
        ];

        // Add Terms Module if configured
        $termsModuleData = $this->getTermsModuleConfig();
        if ($termsModuleData) {
            $loadModules[] = 'terms';
            $modulesConfig['terms'] = $termsModuleData;
        }

        $body = [
            'country' => $config['country'],
            'locale' => $config['locale'],
            'customerType' => $config['customer_type'],
            'product' => [
                'type' => 'payment',
                'intent' => 'payment_one_time'
            ],
            'urls' => [
                'redirect' => $config['redirect_url'],
                'terms' => $config['terms_url']
            ],
            'references' => [
                'quoteId' => (string) $quoteId
            ],
            'data' => [
                'order' => [
                    'currency' => $config['currency'],
                    'amountIncVat' => $amountIncVat,
                    'amountExVat' => $amountExVat,
                    'cart' => $cart
                ],
                'billing' => $billingData,
                'shipping' => $shippingData
            ],
            'hooks' => [
                [
                    'eventType' => 'order_status',
                    'statuses' => [
                        'order_pending',
                        'order_rejected',
                        'order_approved_not_captured'
                    ],
                    'method' => 'POST',
                    'url' => $webhookBaseUrl
                ],
                [
                    'eventType' => 'capture_status',
                    'statuses' => [
                        'pending',
                        'rejected',
                        'approved'
                    ],
                    'method' => 'POST',
                    'url' => $webhookBaseUrl . 'capture'
                ]
            ],
            'modules' => [
                "loadModules" => $loadModules,
                "config" => $modulesConfig
            ]
        ];

        $this->logger->debug('Body before dispatch:', $body);

        $this->eventManager->dispatch('briqpay_payment_module_body_prepare', [
            'body' => &$body,
            'config' => $config,
            'quote' => $quote
        ]);

        if ($this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/strict_rounding', ScopeInterface::SCOPE_STORE)) {
            $body = $this->rounding->roundCart($body);
        }

        $this->logger->debug('Final body before request to ApiClient:', $body);

        try {
            $response = $this->apiClient->request('POST', $uri, $body);

            if ($quote) {
                $quote->setData('briqpay_session_id', $response['sessionId']);
                $this->quoteRepository->save($quote);
            } else {
                $this->logger->error('Quote not found for Quote ID: ' . $quoteId);
                throw new \Exception('Quote not found');
            }

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error creating session: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \Exception('Unable to establish connection to payment service provider.');
        }
    }
}