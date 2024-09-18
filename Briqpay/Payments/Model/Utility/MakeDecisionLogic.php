<?php

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\CompareData;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\PaymentModule\MakeDecision;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Quote\Model\QuoteRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Payment\Model\Checks\Composite as PaymentCompositeChecks;
use Briqpay\Payments\Logger\Logger;

class MakeDecisionLogic
{
    protected $billingData;
    protected $shippingData;
    protected $cart;
    protected $readSession;
    protected $makeDecision;
    protected $compareData;
    protected $logger;
    protected $eventManager;
    protected $quoteRepository;
    protected $checkoutSession;
    protected $paymentCompositeChecks;

    public function __construct(
        AssignBillingAddress $billingData,
        AssignShippingAddress $shippingData,
        GenerateCart $cart,
        ReadSession $readSession,
        MakeDecision $makeDecision,
        CompareData $compareData,
        Logger $logger,
        EventManager $eventManager,
        QuoteRepository $quoteRepository,
        CheckoutSession $checkoutSession,
        PaymentCompositeChecks $paymentCompositeChecks
    ) {
        $this->billingData = $billingData;
        $this->shippingData = $shippingData;
        $this->cart = $cart;
        $this->readSession = $readSession;
        $this->makeDecision = $makeDecision;
        $this->compareData = $compareData;
        $this->logger = $logger;
        $this->eventManager = $eventManager;
        $this->quoteRepository = $quoteRepository;
        $this->checkoutSession = $checkoutSession;
        $this->paymentCompositeChecks = $paymentCompositeChecks;
    }

    public function makeDecision(string $sessionId, $fallbackEmail = null): bool
    {
        $decision = true; // Start with the assumption that the decision will be true
        $validationErrors = []; // Array to hold validation error messages
        $softError = false; // Initialize softError to false
    
        try {
            // Fetch current session data
            $session = $this->readSession->getSession($sessionId);
            $sessionData = $session['data'] ?? [];
    
            // Fetch the quote object using the quote ID from checkout session
            $quoteId = $this->checkoutSession->getQuoteId();
            $quote = $this->quoteRepository->get($quoteId);
    
            // Validate required quote fields before proceeding
            if (!$quote->getCustomerEmail()) {
                $validationErrors[] = 'Customer email is missing.';
                $softError = true; // Set softError to true if an error is found
            }
    
            if (!$quote->getBillingAddress()) {
                $validationErrors[] = 'Billing address is missing.';
                $softError = true; // Set softError to true if an error is found
            }
    
            if (!$quote->getShippingAddress()) {
                $validationErrors[] = 'Shipping address is missing.';
                $softError = true; // Set softError to true if an error is found
            }
    
            // Apply Magento's core payment method checks
            $paymentMethod = $quote->getPayment()->getMethodInstance();
            $checksResult = $this->paymentCompositeChecks->isApplicable($paymentMethod, $quote);
    
            if (!$checksResult) {
                $this->logger->error('Payment method validation failed for session ' . $sessionId);
                return false; // If validation fails, return false immediately
            }
    
            // Fetch current billing, shipping, and cart data
            $billingData = $this->billingData->getBillingData($fallbackEmail);
            $shippingData = $this->shippingData->getShippingData($fallbackEmail);
            $cart = $this->cart->getCart();
    
            // Compare with existing session data
            $billingDataChanged = $this->compareData->compareData($sessionData['billing'] ?? [], $billingData);
            $shippingDataChanged = $this->compareData->compareData($sessionData['shipping'] ?? [], $shippingData);
            $cartTotalCompare = !$this->compareData->doesTotalsMatch($sessionData['order']['amountIncVat'] ?? [], (int) round($quote->getGrandTotal() * 100, 0));
    
            // Log the results of the comparisons
            if ($billingDataChanged) {
                $validationErrors[] = 'Billing data has changed.';
            }
    
            if ($shippingDataChanged) {
                $validationErrors[] = 'Shipping data has changed.';
            }
    
            if ($cartTotalCompare) {
                $validationErrors[] = 'Cart totals do not match.';
            }
    
            // Fire event to allow other modules to affect the decision
            $this->eventManager->dispatch('briqpay_payment_module_decision_prepare', [
                'session' => $session,
                'validation' => &$decision,
                'quote' => $quote
            ]);
    
            // Final validation check
            if (!empty($validationErrors)) {
                // Log all validation errors
                foreach ($validationErrors as $error) {
                    $this->logger->error('Validation error for session ' . $sessionId . ': ' . $error);
                }
                $decision = false; // If any validation errors exist, set decision to false
            }

            $this->logger->debug('Final Decision', ['Final' => $decision]);
            $this->makeDecision->makeDecision($sessionId, $decision, $softError); // Pass softError as the third param
    
            return $decision;
        } catch (\Exception $e) {
            $this->logger->error('Error making decision for session ' . $sessionId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false; // If an exception occurs, decision should be false
        }
    }
}
