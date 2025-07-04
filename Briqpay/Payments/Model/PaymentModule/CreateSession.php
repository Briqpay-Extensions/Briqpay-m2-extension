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
use Magento\Framework\UrlInterface; // Import UrlInterface

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
    protected $urlBuilder; // Add UrlBuilder property
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
        UrlInterface $urlBuilder, // Add UrlBuilder to constructor
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
        $this->urlBuilder = $urlBuilder; // Initialize UrlBuilder
        $this->scopeHelper = $scopeHelper;
    }

    public function getPaymentModule($fallbackEmail = null)
    {
        try {
            $config = $this->setupConfig->getSetupConfig();
            $cart = $this->cart->getCart();
            $billingData = $this->billingData->getBillingData($fallbackEmail);
            $shippingData = $this->shippingData->getShippingData($fallbackEmail);
            $quoteId = $this->checkoutSession->getQuoteId();
            
            // Fetch the quote object using the quote ID
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

       
        // Generate webhook URLs dynamically using the Magento base URL
        $webhookBaseUrl = $this->urlBuilder->getUrl('briqpay/webhooks'); // Assuming 'briqpay/webhook' is the route for your webhooks

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
                    'url' => $webhookBaseUrl // Use dynamic URL
                ],
                [
                    'eventType' => 'capture_status',
                    'statuses' => [
                        'pending',
                        'rejected',
                        'approved'
                    ],
                    'method' => 'POST',
                    'url' => $webhookBaseUrl . 'capture' // Use dynamic URL
                ]
            ],
            'modules' => [
                "loadModules" => [
                    'payment'
                ],
                "config" => [
                    "payment" => [
                        "decision" => [
                            "enabled" => true
                        ]
                    ]
                ]
            ]
        ];

        // Log the body before dispatching the event
        $this->logger->debug('Body before dispatch:', $body);

        // Dispatch event to allow modifications
        $this->eventManager->dispatch('briqpay_payment_module_body_prepare', [
            'body' => &$body, // Passing by reference
            'config' => $config,
            'quote' => $quote
        ]);

        // Log the body after dispatching the event
        $this->logger->debug('Body after dispatch:', $body);

        if ($this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/strict_rounding', ScopeInterface::SCOPE_STORE)) {
            $body = $this->rounding->roundCart($body);
        }
        // Log the body before making the request
        $this->logger->debug('Final body before request to ApiClient:', $body);

        try {
            $response = $this->apiClient->request('POST', $uri, $body);

            // Ensure the quote object is available before using it
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
