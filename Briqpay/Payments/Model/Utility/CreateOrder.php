<?php
namespace Briqpay\Payments\Model\Utility;

use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Session\SessionManagerInterface;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Sales\Model\Order\Payment\TransactionFactory;

class CreateOrder
{
    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var QuoteManagement
     */
    protected $quoteManagement;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var SessionManagerInterface
     */
    protected $sessionManager;

    /**
     * @var ReadSession
     */
    protected $readSession;

    /**
     * @var Order
     */
    protected $order;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    protected $transactionFactory;
    /**
     * CreateOrder constructor.
     * @param QuoteFactory $quoteFactory
     * @param QuoteManagement $quoteManagement
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param SessionManagerInterface $sessionManager
     * @param ReadSession $readSession
     * @param Order $order
     * @param Logger $logger
     */
    public function __construct(
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        SessionManagerInterface $sessionManager,
        ScopeConfigInterface $scopeConfig,
        ReadSession $readSession,
        Order $order,
        Logger $logger,
        TransactionFactory $transactionFactory,
    ) {
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->sessionManager = $sessionManager;
        $this->readSession = $readSession;
        $this->order = $order;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->transactionFactory = $transactionFactory;
    }

    /**
     * Create an order based on Briqpay session data.
     *
     * @throws LocalizedException
     */
    public function createOrder()
    {
        try {
            $sessionId = $this->sessionManager->getData('briqpay_session_id');
// Load the last quote from the checkout session
            $quote = $this->checkoutSession->getQuote();

            if (!$quote->getId()) {
                throw new LocalizedException(__('Quote not found.'));
            }
            if (!$quote->getIsActive()) {
                $this->logger->info('Attempted to create order using quote that was already used: ' .$quote->getReservedOrderId());
                return;
            }
            if (!isset($sessionId)) {
                $this->logger->info('Empty session id');
                return;
            }
            // Load the session data
            $session = $this->readSession->getSession($sessionId);

            if ($session['status'] !== 'completed' && $session['status'] !== 'pending') {
                throw new LocalizedException(__('Error creating order for session ' . $sessionId));
            }

            $this->logger->info('Session create order: ' . json_encode($session));

            // Extract transactions
            $transactions = $session['data']['transactions'] ?? [];
            $pspDisplayName = !empty($transactions) ? $transactions[0]['pspDisplayName'] : '';
            $pspReservationId = !empty($transactions) ? $transactions[0]['reservationId'] : '';
            $jwt = $session['clientToken'];
            $briqpaySessionStatus = !empty($transactions) ? $transactions[0]['status'] : '';

            // Initialize variables for business-specific data
            $companyCin = null;
            $companyName = null;
            $extraDataStrongAuth = null;
            $companyVatno = null;
            // Check if session is business type and 'company' data exists
            if ($session['customerType'] === 'business' && isset($session['data']['company'])) {
                $company = $session['data']['company'];
                $companyCin = $company['cin'] ?? null;
                $companyName = $company['name'] ?? null;
                $companyVatno = $company['vatNumber'] ?? null;
            }

            // Check if 'strongAuth' exists and ensure 'output' and 'provider' fields exist
            if (isset($session['data']['strongAuth'])) {
                $strongAuth = $session['data']['strongAuth'];

                if (isset($strongAuth['output']) && isset($strongAuth['provider'])) {
                    $extraDataStrongAuth = base64_encode(json_encode($strongAuth));
                } else {
                    $this->logger->error('Missing required fields in strongAuth.');
                }
            } else {
                $this->logger->info('No strongAuth found.');
            }

            // Decode the JWT (without verification)
            $jwtExplode = explode('.', $jwt);
            $decodedArray = json_decode(base64_decode($jwtExplode[1]), true);

            $merchantId = $decodedArray['merchantId'];
            $testmode = $this->scopeConfig->getValue('payment/briqpay/test_mode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $backofficeUrl = 'https://app.briqpay.com/dashboard/sessions/orders/' . $sessionId . '?test='.$testmode.'&merchantId=' . $merchantId;

            

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
                $billingAddress = $quote->getBillingAddress();
                if ($billingAddress) {
                    $quote->setCustomerEmail($billingAddress->getEmail());
                }
            }
            $quote->setData('briqpay_psp_display_name', $pspDisplayName);

            

            // Collect totals and save quote
            $quote->collectTotals()->save();

            

            
            // Convert quote to order
            $order = $this->quoteManagement->submit($quote);

            if (!$order || !$order->getId()) {
                throw new LocalizedException(__('Order could not be created.'));
            }

           
            

            // Set the order status to "Pending"
            $order->setState(Order::STATE_NEW);
            $order->setStatus('pending');

            // Save additional data to the order
            $order->setData('briqpay_psp_display_name', $pspDisplayName);
            $order->setData('briqpay_psp_reservationId', $pspReservationId);
            $order->setData('briqpay_session_id', $sessionId);
            $order->setData('briqpay_backoffice_url', $backofficeUrl);
            $order->setData('briqpay_session_status', $briqpaySessionStatus);

            // Save business specific data to the order if customer is 'business'
            if ($session['customerType'] === 'business') {
                $order->setData('briqpay_cin', $companyCin);
                $order->setData('briqpay_company_name', $companyName);
                $order->setData('briqpay_company_vatNo', $companyVatno);
            }

            // Save strongAuth data to the order if it exists
            if (isset($extraDataStrongAuth)) {
                $order->setData('briqpay_strong_auth', $extraDataStrongAuth);
            }

            // Save order
            $order->save();

            // Save order details to session
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastSuccessQuoteId($this->checkoutSession->getQuoteId());
            $this->checkoutSession->setLastQuoteId($this->checkoutSession->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getId());
        } catch (\Exception $e) {
            $this->logger->error('Error creating order for ' . $sessionId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new LocalizedException(__('Error creating order for session ' . $sessionId));
        }
    }
}
