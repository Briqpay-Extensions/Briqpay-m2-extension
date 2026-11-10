<?php
namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Model\OrderManagement\AutoCaptureInvoicer;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Session\SessionManagerInterface;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\Utility\ScopeHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Sales\Model\Order\Payment\TransactionFactory;

class CreateOrder
{
    private ?AutoCaptureInvoicer $autoCaptureInvoicer;

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
     * @var Logger
     */
    protected $logger;

    protected $transactionFactory;

    protected $scopeHelper;

    /**
     * @var OrderCreationGuard
     */
    private $guard;

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
        ScopeHelper $scopeHelper,
        ?OrderCreationGuard $guard = null,
        ?AutoCaptureInvoicer $autoCaptureInvoicer = null
    ) {
        $this->autoCaptureInvoicer = $autoCaptureInvoicer;
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
        $this->scopeHelper = $scopeHelper;
        $this->guard = $guard
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(OrderCreationGuard::class);
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
                $this->logger->info('Quote not found');
                throw new LocalizedException(__('Quote not found.'));
            }

            if (!isset($sessionId)) {
                $this->logger->info('Empty sessionId under sessionmanager,  attempting to fetch sessionId from Quote');
                $sessionId = $quote->getData('briqpay_session_id');
            }

            if (!$quote->getIsActive()) {
                $this->logger->info('Attempted to create order using quote that was already used: ' .$quote->getReservedOrderId());
                throw new LocalizedException(__('Quote already converted to order'));
            }
            if (!isset($sessionId)) {
                $this->logger->info('Empty session id:' . $sessionId);
                throw new LocalizedException(__('Sessionid not found.'));
            }
            // Load the session data
            $session = $this->readSession->getSession($sessionId);
            $this->guard->assertPaidFor($session, $quote->getId());

            $this->guard->withLock((string) $sessionId, function () use ($quote, $session, $sessionId) {
                if ($this->guard->existingOrder($quote->getId()) !== null) {
                    throw new LocalizedException(__('Quote already converted to order'));
                }
                $this->placeOrder($quote, $session, (string) $sessionId);
            }, $quote->getId());
        } catch (\Exception $e) {
            $this->logger->error('Error creating order for ' . $sessionId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new LocalizedException(__('Error creating order for session ' . $sessionId));
        }
    }

    /**
     * Places the order for a paid session, holding the session's lock (see createOrder()).
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array<string, mixed> $session
     * @param string $sessionId
     * @return void
     * @throws \Exception
     */
    private function placeOrder($quote, array $session, string $sessionId): void
    {
        // The session holds personal data: in full only at debug level.
        $this->logger->info('Creating the order for a Briqpay session.', [
            'sessionId' => $sessionId,
            'status' => $session['status'] ?? null,
        ]);
        $this->logger->debug('Briqpay session for the order', ['session' => $session]);

        // Extract PspMetaData
        $pspMetaData = $session['data']['pspMetadata'] ?? [];

        // Extract transactions
        $transactions = $session['data']['transactions'] ?? [];
        $pspDisplayName = (string) ($transactions[0]['pspDisplayName'] ?? '');
        $pspProviderName = (string) ($transactions[0]['pspIntegrationName'] ?? '');
        $pspReservationId = (string) ($transactions[0]['reservationId'] ?? '');
        $jwt = (string) ($session['clientToken'] ?? '');
        $briqpaySessionStatus = (string) ($transactions[0]['status'] ?? '');
        $secondaryReference = $transactions[0]['secondaryReservationId'] ?? '';
        $underlyingPspName = $pspMetaData['description'] ?? '';

        // Initialize variables for business-specific data
        $companyCin = null;
        $companyName = null;
        $extraDataStrongAuth = null;
        $companyVatno = null;
        // Check if session is business type and 'company' data exists
        if (($session['customerType'] ?? null) === 'business' && isset($session['data']['company'])) {
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

        // The merchant id for the order's backoffice link, from the session's client token.
        $jwtExplode = explode('.', $jwt);
        $decodedArray = isset($jwtExplode[1]) ? json_decode((string) base64_decode($jwtExplode[1]), true) : null;

        $merchantId = (string) (is_array($decodedArray) ? ($decodedArray['merchantId'] ?? '') : '');
        $testmode = $this->scopeHelper->getScopedConfigValue('payment/briqpay/test_mode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
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
            // The guest's email: the billing address's, else the one the checkout saved on
            // the quote, else what Briqpay holds; never emptied.
            $billingAddress = $quote->getBillingAddress();
            $email = $billingAddress ? (string) $billingAddress->getEmail() : '';
            if ($email === '') {
                $email = (string) $quote->getCustomerEmail();
            }
            if ($email === '') {
                $email = (string) ($session['data']['billing']['email'] ?? '');
            }
            if ($email !== '') {
                $quote->setCustomerEmail($email);
            }
        }
        $quote->setData('briqpay_psp_display_name', $pspDisplayName);

        // Collect totals and save quote
        $quote->collectTotals()->save();

        

        
        // Convert quote to order
        try {
            $order = $this->guard->placing(function () use ($quote) {
                return $this->quoteManagement->submit($quote);
            });
        } catch (\Exception $submitError) {
            // Magento can fail after it has saved the order (while saving the quote,
            // say); that order is completed here rather than a second one created.
            $order = $this->guard->existingOrder($quote->getId());
            if ($order === null) {
                throw $submitError;
            }
            $this->logger->warning('Order was placed although submitting the quote failed.', [
                'orderId' => $order->getIncrementId(),
                'error' => $submitError->getMessage(),
            ]);
            $quote->setIsActive(false);
        }

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
        $order->setData('briqpay_psp_provider', $pspProviderName);
        $order->setData('briqpay_psp_underlying_payment_method', $underlyingPspName);
        $order->setData('briqpay_secondary_reservationId', $secondaryReference);

        // Save business specific data to the order if customer is 'business'
        if (($session['customerType'] ?? null) === 'business') {
            $order->setData('briqpay_cin', $companyCin);
            $order->setData('briqpay_company_name', $companyName);
            $order->setData('briqpay_company_vatNo', $companyVatno);
        }

        // Save strongAuth data to the order if it exists
        if (isset($extraDataStrongAuth)) {
            $order->setData('briqpay_strong_auth', $extraDataStrongAuth);
        }

        $terms = $session['data']['terms'] ?? [];

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

        $this->guard->holdIfSessionDiffers($order, $quote, $session);

        // Save order
        $order->save();

        // A capture the payment method made by itself (Swish) may have been reported
        // before the order existed; it is invoiced now rather than on a webhook retry.
        $this->autoCaptureInvoicer()->invoiceExisting($order, $session);

        // Save order details to session
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastSuccessQuoteId($this->checkoutSession->getQuoteId());
        $this->checkoutSession->setLastQuoteId($this->checkoutSession->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getId());
    }

    private function autoCaptureInvoicer(): AutoCaptureInvoicer
    {
        $this->autoCaptureInvoicer = $this->autoCaptureInvoicer
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(AutoCaptureInvoicer::class);

        return $this->autoCaptureInvoicer;
    }
}
