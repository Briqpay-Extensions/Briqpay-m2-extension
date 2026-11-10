<?php
namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\OrderManagement\SessionLedger;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\Utility\CompanyName;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
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

            // The "name" field when given, else a generated key.
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

    /**
     * The customer's company for a business session, from the quote's addresses.
     *
     * Briqpay takes company data only when the session is created. Magento holds a
     * company name but no organisation number, so `cin` is left for the customer to
     * enter in the checkout.
     *
     * @param array<string, mixed> $body
     * @param \Magento\Quote\Model\Quote $quote
     */
    private function addCompany(array &$body, $quote): void
    {
        $names = CompanyName::fromQuote($quote);
        if ($names['billing'] !== '') {
            $body['data']['company'] = ['name' => $names['billing']];
            // Kept on the session as sent, so a later request compares the billing company
            // with the name sent rather than with the company block Briqpay returns.
            $body['references'][CompanyName::REFERENCE] = mb_substr($names['billing'], 0, 512);
        }
        $body['data'] = CompanyName::onAddresses($body['data'], $names);
    }

    public function getPaymentModule($fallbackEmail = null)
    {
        try {
            $config = $this->setupConfig->getSetupConfig();
            $cartData = $this->cart->getCartData();
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
        $cart = $cartData['cart'];
        $amountIncVat = $cartData['amountIncVat'];
        $amountExVat = $cartData['amountExVat'];
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
                'quoteId' => (string) $quoteId,
                // Captures and refunds of this session send each Magento document's own
                // line amounts (see SessionLedger::documentAmounts()).
                SessionLedger::LINE_AMOUNTS_REFERENCE => SessionLedger::LINE_AMOUNTS_MAGENTO,
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
                    // The statuses the module acts on; HandleWebhook maps a few more for
                    // webhooks replayed by hand.
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

        // A virtual quote has no shipping address to prefill.
        $body['data'] = array_filter($body['data'], static function ($block): bool {
            return $block !== null;
        });

        // Only the payment module is ever loaded. A business session carries the company
        // name from the address; a merchant with the organisation number adds it on the
        // briqpay_payment_module_body_prepare event.
        if ($config['customer_type'] === 'business') {
            $this->addCompany($body, $quote);
        }

        $this->logger->debug('Body before dispatch:', ['body' => $body]);

        $this->eventManager->dispatch('briqpay_payment_module_body_prepare', [
            'body' => &$body,
            'config' => $config,
            'quote' => $quote
        ]);

        $this->logger->debug('Final body before request to ApiClient:', ['body' => $body]);

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