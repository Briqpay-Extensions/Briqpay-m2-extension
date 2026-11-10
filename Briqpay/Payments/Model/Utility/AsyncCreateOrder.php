<?php

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Model\OrderManagement\AutoCaptureInvoicer;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Model\Order;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\ScopeHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;

class AsyncCreateOrder
{
    private ?SessionOrderData $sessionOrderData = null;

    private ?AutoCaptureInvoicer $autoCaptureInvoicer;

    protected $quoteRepository;
    protected $quoteManagement;
    protected $customerRepository;
    protected $orderRepository;
    protected $logger;
    protected $customerSession;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    protected $scopeHelper;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private QuoteResource $quoteResource;
    private OrderCreationGuard $guard;

    // Briqpay order status to Magento state and status for an order created from a
    // webhook; kept in step with the mapping in HandleWebhook.
    protected $statusMapping = [
        'order_pending' => [
            'state' => Order::STATE_NEW,
            // As HandleWebhook moves it: Magento's "pending" status of a new order.
            'status' => 'pending',
        ],
        'order_approved_not_captured' => [
            'state' => Order::STATE_PROCESSING,
            'status' => Order::STATE_PROCESSING,
        ],
        'captured_full' => [
            'state' => Order::STATE_PROCESSING,
            'status' => Order::STATE_PROCESSING,
        ],
        'captured_partial' => [
            'state' => Order::STATE_PROCESSING,
            'status' => Order::STATE_PROCESSING,
        ],
    ];

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        QuoteManagement $quoteManagement,
        CustomerRepositoryInterface $customerRepository,
        OrderRepositoryInterface $orderRepository,
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        CustomerSession $customerSession,
        ScopeHelper $scopeHelper,
        ?SearchCriteriaBuilder $searchCriteriaBuilder = null,
        ?QuoteResource $quoteResource = null,
        ?OrderCreationGuard $guard = null,
        ?AutoCaptureInvoicer $autoCaptureInvoicer = null,
        ?SessionOrderData $sessionOrderData = null
    ) {
        $this->autoCaptureInvoicer = $autoCaptureInvoicer;
        $this->sessionOrderData = $sessionOrderData;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->customerRepository = $customerRepository;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->customerSession = $customerSession;
        $this->scopeConfig = $scopeConfig;
        $this->scopeHelper = $scopeHelper;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(SearchCriteriaBuilder::class);
        $this->quoteResource = $quoteResource
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(QuoteResource::class);
        $this->guard = $guard
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(OrderCreationGuard::class);
    }

    public function createOrderFromWebhook($quoteId, $sessionData)
    {
        try {
            $this->guard->withLock((string) ($sessionData['sessionId'] ?? ''), function () use ($quoteId, $sessionData) {
                $this->createOrder($quoteId, $sessionData);
            }, $quoteId);
        } catch (\Exception $e) {
            $this->logger->error('Error creating order from webhook for quote ID: ' . $quoteId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new LocalizedException(__('Error creating order from webhook for quote ID: ' . $quoteId));
        }
    }

    /**
     * Creates the order, holding the session's lock (see createOrderFromWebhook()).
     *
     * @param int|string $quoteId
     * @param array<string, mixed> $sessionData
     * @return void
     * @throws \Exception
     */
    private function createOrder($quoteId, array $sessionData): void
    {
        // The session holds personal data: in full only at debug level.
        $this->logger->info('Creating the order for a Briqpay session from its webhook.', [
            'sessionId' => $sessionData['sessionId'] ?? null,
            'status' => $sessionData['status'] ?? null,
        ]);
        $this->logger->debug('Briqpay session for the order', ['session' => $sessionData]);

        // Load quote
        $quote = $this->quoteRepository->get($quoteId);

        if (!$quote->getId()) {
            throw new LocalizedException(__('Quote not found.'));
        }
        $existing = $this->guard->existingOrder($quote->getId());
        if ($existing !== null) {
            $this->logger->info('The order for this quote was already created.', [
                'orderIncrementId' => $existing->getIncrementId(),
            ]);

            return;
        }
        $this->guard->assertPaidFor($sessionData, $quote->getId());

        // Validate email format
        $billingEmail = $this->extractBillingEmail($quote, $sessionData);
        if (!filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('The billing address has no valid e-mail; the order cannot be created.'));
        }

        // Extract moduleStatus
        $moduleStatus = $sessionData['moduleStatus'] ?? [];

        // Determine order status from moduleStatus
        if (isset($moduleStatus['payment']['orderStatus'])) {
            $incomingStatus = $moduleStatus['payment']['orderStatus'];
        } else {
            throw new LocalizedException(__('Order status not found in moduleStatus.'));
        }

        // Set customer information
        $this->setCustomerInformation($quote, $billingEmail);
        $transactions = $sessionData['data']['transactions'] ?? [];
        $pspMetaData = $sessionData['data']['pspMetadata'] ?? [];
        $pspDisplayName = (string) ($transactions[0]['pspDisplayName'] ?? '');
        $quote->setData('briqpay_psp_display_name', $pspDisplayName); 

        // Collect totals and save quote
        $quote->collectTotals()->save();

        // Convert quote to order
        try {
            $order = $this->guard->placing(function () use ($quote) {
                return $this->quoteManagement->submit($quote);
            });
        } catch (\Exception $submitError) {
            // Magento can fail after it has saved the order, for example while saving
            // the quote; the order then exists and is completed here instead of a
            // second one being created later.
            $order = $this->placedOrder($quote);
            if ($order === null) {
                throw $submitError;
            }
            $this->logger->warning('Order was placed although submitting the quote failed.', [
                'orderId' => $order->getIncrementId(),
                'error' => $submitError->getMessage(),
            ]);
            $this->closeQuote($quote);
        }

        if (!$order || !$order->getId()) {
            throw new LocalizedException(__('Order could not be created.'));
        }

        $terms = $sessionData['data']['terms'] ?? [];

        if (!empty($terms)) {
            $termsDataForDb = [];
            foreach ($terms as $key => $termData) {
                // Keyed by the term's key name from the module's settings.
                $termsDataForDb[$key] = [
                    'value'  => (isset($termData['value']) && $termData['value'] === true),
                    'header' => $termData['header'] ?? $key
                ];

                // Each term also on the payment's additional information, by its key.
                $order->getPayment()->setAdditionalInformation($key, !empty($termData['value']) ? 1 : 0);
            }

            // All accepted terms, as JSON.
            $order->setData('briqpay_accepted_terms', json_encode($termsDataForDb));
        } 

        // At this point, the order has been created successfully.
        $this->logger->info('Order created successfully with order ID: ' . $order->getId());

        // Now, apply the state and status based on incoming status
        if (isset($this->statusMapping[$incomingStatus])) {
            $state = $this->statusMapping[$incomingStatus]['state'];
            $status = $this->statusMapping[$incomingStatus]['status'];

            $order->setState($state);
            $order->setStatus($status);

            // Additional data related to the order
            $this->setOrderAdditionalData($order, $sessionData);
            $this->guard->holdIfSessionDiffers($order, $quote, $sessionData);

            // Save order
            $order->save();

            // A capture the payment method made by itself (Swish) may have been reported
            // before the order existed; it is invoiced now rather than on a webhook retry.
            $this->autoCaptureInvoicer()->invoiceExisting($order, $sessionData);
        } else {
            throw new LocalizedException(__('Unexpected order status received: %1', $incomingStatus));
        }
    }

    /**
     * The order Magento saved for the quote, if any.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return Order|null
     */
    private function placedOrder($quote): ?Order
    {
        if (!$quote->getReservedOrderId()) {
            return null;
        }
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $quote->getReservedOrderId())
            ->addFilter('quote_id', $quote->getId())
            ->setPageSize(1)
            ->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($orders);

        return $order instanceof Order ? $order : null;
    }

    /**
     * Deactivates a quote that has become an order, so it cannot be ordered a second time.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return void
     */
    private function closeQuote($quote): void
    {
        $quote->setIsActive(false);
        try {
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            // The repository validates the addresses again; the flag alone still has to be stored.
            try {
                $this->quoteResource->save($quote);
            } catch (\Exception $resourceError) {
                $this->logger->critical('Could not deactivate the quote of a placed order.', [
                    'quoteId' => $quote->getId(),
                    'error' => $resourceError->getMessage(),
                ]);
            }
        }
    }

    protected function setCustomerInformation($quote, $billingEmail)
    {
        // A webhook request has no customer session, so a quote that already belongs to a
        // customer keeps that customer. Turning it into a guest quote leaves its
        // addresses pointing at the customer's saved addresses, which Magento rejects
        // once the order is placed.
        if ($quote->getCustomerId() && !$this->customerSession->isLoggedIn()) {
            $quote->setCustomerIsGuest(false);

            return;
        }

        // Set customer information
        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $quote->setCustomerId($customer->getId());
            $quote->setCustomerIsGuest(false);
            $quote->setCustomerGroupId($customer->getGroupId());
            $quote->setCustomerEmail($customer->getEmail());
        } else {
            $quote->setCustomerId(null);
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
            $quote->setCustomerEmail($billingEmail);
        }
    }

    protected function setOrderAdditionalData($order, $sessionData)
    {
        $this->sessionOrderData()->apply($order, $sessionData);
    }


    protected function extractBillingEmail($quote, $sessionData)
    {
        // The email Briqpay holds for the billing address (data.billing in the session
        // as Briqpay returns it), else the quote's billing address, else the quote's.
        $billingEmail = $sessionData['data']['billing']['email'] ?? $sessionData['billing']['email'] ?? '';
        if (!$billingEmail) {
            $billingAddress = $quote->getBillingAddress();
            if ($billingAddress) {
                $billingEmail = $billingAddress->getEmail();
            }
        }
        if (!$billingEmail) {
            $billingEmail = (string) $quote->getCustomerEmail();
        }
        return $billingEmail;
    }

    private function autoCaptureInvoicer(): AutoCaptureInvoicer
    {
        $this->autoCaptureInvoicer = $this->autoCaptureInvoicer
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(AutoCaptureInvoicer::class);

        return $this->autoCaptureInvoicer;
    }

    private function sessionOrderData(): SessionOrderData
    {
        $this->sessionOrderData = $this->sessionOrderData
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(SessionOrderData::class);

        return $this->sessionOrderData;
    }
}
