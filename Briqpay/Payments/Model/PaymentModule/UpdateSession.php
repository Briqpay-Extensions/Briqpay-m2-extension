<?php

namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\Utility\CompanyName;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
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
     * @var Logger
     */
    private $logger;

    protected $eventManager;
    protected $checkoutSession;
    protected $quoteRepository;
    private $scopeConfig;

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
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        ManagerInterface $eventManager
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->cart = $cart;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->billingData = $billingData;
        $this->shippingData = $shippingData;
        $this->eventManager = $eventManager;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
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
            $cartData = $this->cart->getCartData();
            $billingData = $this->billingData->getBillingData($fallbackEmail);
            $shippingData = $this->shippingData->getShippingData($fallbackEmail);

            $uri = '/v3/session/' . $sessionId;

            $body = [
            'data' => [
                'order' => [
                    'currency' => $config['currency'],
                    'amountIncVat' => $cartData['amountIncVat'],
                    'amountExVat' => $cartData['amountExVat'],
                    'cart' => $cartData['cart']
                ],
                'billing' => $billingData,
                'shipping' => $shippingData
            ]
            ];
            $body['data'] = array_filter($body['data'], static function ($block): bool {
                return $block !== null;
            });
        
            $quoteId = $this->checkoutSession->getQuoteId();
            $quote = $this->quoteRepository->getActive($quoteId);
            if (($config['customer_type'] ?? '') === 'business') {
                // The company block is the session's from its creation; the names on the
                // addresses go with every update, as at creation.
                $body['data'] = CompanyName::onAddresses($body['data'], CompanyName::fromQuote($quote));
            }

            $this->eventManager->dispatch('briqpay_payment_module_update_prepare', [
                'body' => &$body, // Passing by reference
                'config' => $config,
                'quote' => $quote
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error dispatching event ' . $e->getMessage(), [
                'exception' => $e,
                'sessionId' => $sessionId,
            ]);
            $this->logger->debug('Briqpay session update that failed', ['body' => $body]);
            throw new \Exception('Error dispatching event session', 0, $e);
        }

        try {
            $response = $this->apiClient->request('PATCH', $uri, $body);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error updating session: ' . $e->getMessage(), [
                'exception' => $e,
                'sessionId' => $sessionId,
            ]);
            $this->logger->debug('Briqpay session update that failed', ['body' => $body]);
            throw new \Exception('Error updating session', 0, $e);
        }
    }
}
