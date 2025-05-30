<?php

namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Model\Utility\RoundingHelper;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\ScopeHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;

class UpdateSession
{
    /**
     * @var SetupConfig
     */
    private $setupConfig;

    /**
     * @var GenerateCart
     */
    private $cart;

    /**
     * @var AssignBillingAddress
     */
    private $billingData;

    /**
     * @var AssignShippingAddress
     */
    private $shippingData;

    /**
     * @var ApiClient
     */
    private $apiClient;

    /**
     * @var LoggerInterface
     */
    private $logger;

    protected $eventManager;
    protected $checkoutSession;
    protected $quoteRepository;
    protected $rounding;
    private $scopeConfig;
    protected $scopeHelper;

    /**
     * UpdateSession constructor.
     *
     * @param SetupConfig $setupConfig
     * @param ApiClient $apiClient
     * @param GenerateCart $cart
     * @param AssignBillingAddress $billingData
     * @param AssignShippingAddress $shippingData
     * @param Logger $logger
     */
    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        GenerateCart $cart,
        AssignBillingAddress $billingData,
        AssignShippingAddress $shippingData,
        RoundingHelper $rounding,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        ManagerInterface $eventManager,
        ScopeHelper $scopeHelper,
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->cart = $cart;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->billingData = $billingData;
        $this->shippingData = $shippingData;
        $this->rounding = $rounding;
        $this->eventManager = $eventManager;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->scopeHelper = $scopeHelper;
    }

    /**
     * Updates the session with the given session ID.
     *
     * @param string $sessionId
     * @return array
     * @throws \Exception
     */
    public function updateSession(string $sessionId, $fallbackEmail = null): array
    {
        try {
            $config = $this->setupConfig->getSetupConfig();
            $cartItems = $this->cart->getCart();
            $billingData = $this->billingData->getBillingData($fallbackEmail);
            $shippingData = $this->shippingData->getShippingData($fallbackEmail);
       
            $uri = '/v3/session/' . $sessionId;
       
            $body = [
            'data' => [
                'order' => [
                    'currency' => $config['currency'],
                    'amountIncVat' => $this->cart->getTotalAmount(),
                    'amountExVat' => $this->cart->getTotalExAmount(),
                    'cart' => $cartItems
                ],
                'billing' => $billingData,
                'shipping' => $shippingData
            ]
            ];
        
            $quoteId = $this->checkoutSession->getQuoteId();
            $quote = $this->quoteRepository->getActive($quoteId);

            $this->eventManager->dispatch('briqpay_payment_module_update_prepare', [
                'body' => &$body, // Passing by reference
                'config' => $config,
                'quote' => $quote
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error dispatching event ' . $e->getMessage(), [
                'exception' => $e,
                'sessionId' => $sessionId,
                'body' => $body
            ]);
            throw new \Exception('Error dispatching event session', 0, $e);
        }

        if ($this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/strict_rounding', ScopeInterface::SCOPE_STORE)) {
            $body = $this->rounding->roundCart($body);
        }
        
        try {
            $response = $this->apiClient->request('PATCH', $uri, $body);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error updating session: ' . $e->getMessage(), [
                'exception' => $e,
                'sessionId' => $sessionId,
                'body' => $body
            ]);
            throw new \Exception('Error updating session', 0, $e);
        }
    }
}
