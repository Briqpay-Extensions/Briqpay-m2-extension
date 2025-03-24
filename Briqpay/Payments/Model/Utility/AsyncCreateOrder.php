<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Model\Order;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;

class AsyncCreateOrder
{
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

    // Define status mapping
    protected $statusMapping = [
        'order_pending' => [
            'state' => Order::STATE_NEW,
            'status' => Order::STATE_PENDING_PAYMENT,
        ],
        'order_approved_not_captured' => [
            'state' => Order::STATE_PROCESSING,
            'status' => Order::STATE_PROCESSING,
        ],
        'captured_full' => [
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
        CustomerSession $customerSession
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->customerRepository = $customerRepository;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->customerSession = $customerSession;
        $this->scopeConfig = $scopeConfig;
    }

    public function createOrderFromWebhook($quoteId, $sessionData)
    {
        try {
            $this->logger->info('Received webhook payload: ' . json_encode($sessionData));
    
            // Load quote
            $quote = $this->quoteRepository->get($quoteId);
    
            if (!$quote->getId()) {
                throw new LocalizedException(__('Quote not found.'));
            }
    
            // Validate email format
            $billingEmail = $this->extractBillingEmail($quote, $sessionData);
            if (!filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
                throw new LocalizedException(__('Invalid email format for billing address: %1', $billingEmail));
            }
    
            // Extract moduleStatus
            $moduleStatus = $sessionData['moduleStatus'] ?? [];
    
            // Determine order status from moduleStatus
            if (isset($moduleStatus['payment']['orderStatus'])) {
                $incomingStatus = $moduleStatus['payment']['orderStatus'];
            } else {
                throw new LocalizedException(__('Order status not found in moduleStatus.'));
            }
    
            // Check if the order has already been processed
            $reservedOrderId = $quote->getReservedOrderId();
            if ($reservedOrderId) {
                // This is before you attempt to submit the order, so don't look for the order yet.
                $this->logger->debug('Quote has reserved order ID: ' . $reservedOrderId);
            }
    
            // Set customer information
            $this->setCustomerInformation($quote, $billingEmail);
            $transactions = $sessionData['data']['transactions'] ?? [];
            $pspDisplayName = !empty($transactions) ? $transactions[0]['pspDisplayName'] : '';
            $quote->setData('briqpay_psp_display_name', $pspDisplayName);
    
            // Collect totals and save quote
            $quote->collectTotals()->save();
    
            // Convert quote to order
            $order = $this->quoteManagement->submit($quote);
    
            if (!$order || !$order->getId()) {
                throw new LocalizedException(__('Order could not be created.'));
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
    
                // Save order
                $order->save();
            } else {
                throw new LocalizedException(__('Unexpected order status received: %1', $incomingStatus));
            }
        } catch (\Exception $e) {
            $this->logger->error('Error creating order from webhook for quote ID: ' . $quoteId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new LocalizedException(__('Error creating order from webhook for quote ID: ' . $quoteId));
        }
    }

    protected function setCustomerInformation($quote, $billingEmail)
    {
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
        
        
        // Extract transactions
        $transactions = $sessionData['data']['transactions'] ?? [];
        
        $pspDisplayName = !empty($transactions) ? $transactions[0]['pspDisplayName'] : '';
        $pspReservationId = !empty($transactions) ? $transactions[0]['reservationId'] : '';
        $briqpaySessionStatus = !empty($transactions) ? $transactions[0]['status'] : '';
    
       
    
        // Set PSP display name
        if ($pspDisplayName !== '') {
            $order->setData('briqpay_psp_display_name', $pspDisplayName);
        } else {
            $this->logger->debug('PSP Display Name is empty.');
        }
    
        // Set reservation ID
        if ($pspReservationId !== '') {
            $order->setData('briqpay_psp_reservationId', $pspReservationId);
        } else {
            $this->logger->debug('PSP Reservation ID is empty.');
        }
    
        // Set session ID
        $sessionId = $sessionData['sessionId'] ?? '';
        if ($sessionId !== '') {
            $order->setData('briqpay_session_id', $sessionId);
            $this->logger->info('Set briqpay_session_id to ' . $sessionId);
        } else {
            $this->logger->info('Session ID is empty.');
        }
    
        // Construct backoffice URL
        $merchantId = '';
        if (isset($sessionData['clientToken'])) {
            $jwt = $sessionData['clientToken'];
            $jwtExplode = explode('.', $jwt);
            if (isset($jwtExplode[1])) {
                $decodedArray = json_decode(base64_decode($jwtExplode[1]), true);
                $merchantId = $decodedArray['merchantId'] ?? '';
            } else {
                $this->logger->warning('JWT does not contain expected payload.');
            }
        } else {
            $this->logger->warning('Client token not found in session data.');
        }
        
        if ($merchantId !== '' && $sessionId !== '') {
            $testmode = $this->scopeConfig->getValue('payment/briqpay/test_mode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $backofficeUrl = 'https://app.briqpay.com/dashboard/sessions/orders/' . $sessionId . '?test=' . $testmode . '&merchantId=' . $merchantId;
            $order->setData('briqpay_backoffice_url', $backofficeUrl);
        } else {
            $this->logger->warning('Cannot construct backoffice URL, merchantId or sessionId is empty.');
        }
    
        // Set session status
        if ($briqpaySessionStatus !== '') {
            $order->setData('briqpay_session_status', $briqpaySessionStatus);
        } else {
            $this->logger->warning('Briqpay Session Status is empty.');
        }
    
        // Set business-specific data
        if ($sessionData['customerType'] === 'business' && isset($sessionData['data']['company'])) {
            $company = $sessionData['data']['company'];
            $companyCin = $company['cin'] ?? null;
            $companyName = $company['name'] ?? null;
            $companyVatno = $company['vatNumber'] ?? null;
    
           
            
            if ($companyCin !== null) {
                $order->setData('briqpay_cin', $companyCin);
            }
            if ($companyName !== null) {
                $order->setData('briqpay_company_name', $companyName);
            }
            if ($companyVatno !== null) {
                $order->setData('briqpay_company_vatNo', $companyVatno);
            }
        } else {
            $this->logger->warning('Customer is not a business or company data not found.');
        }
    
        // Set strongAuth data
        if (isset($sessionData['data']['strongAuth'])) {
            $strongAuth = $sessionData['data']['strongAuth'];
            if (isset($strongAuth['output']) && isset($strongAuth['provider'])) {
                $extraDataStrongAuth = base64_encode(json_encode($strongAuth));
                $order->setData('briqpay_strong_auth', $extraDataStrongAuth);
            } else {
                $this->logger->error('Missing required fields in strongAuth.');
            }
        } else {
            $this->logger->info('No strongAuth found.');
        }
        
        // Log end of function
        $this->logger->info('Finished setOrderAdditionalData function.');
    }
    

    protected function extractBillingEmail($quote, $sessionData)
    {
        // Extract billing email from session data or quote billing address
        $billingEmail = $sessionData['billing']['email'] ?? '';
        if (!$billingEmail) {
            $billingAddress = $quote->getBillingAddress();
            if ($billingAddress) {
                $billingEmail = $billingAddress->getEmail();
            }
        }
        return $billingEmail;
    }
}
