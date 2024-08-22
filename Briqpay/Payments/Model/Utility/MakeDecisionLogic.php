<?php

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\CompareData;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\PaymentModule\MakeDecision;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Quote\Model\QuoteRepository; // Correct interface
use Magento\Checkout\Model\Session as CheckoutSession;
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

    public function __construct(
        AssignBillingAddress $billingData,
        AssignShippingAddress $shippingData,
        GenerateCart $cart,
        ReadSession $readSession,
        MakeDecision $makeDecision,
        CompareData $compareData,
        Logger $logger,
        EventManager $eventManager,
        QuoteRepository $quoteRepository, // Correct interface
        CheckoutSession $checkoutSession
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
    }

    public function makeDecision(string $sessionId, $fallbackEmail = null): bool
    {
        try {
            // Fetch current session data
            $session = $this->readSession->getSession($sessionId);
            $sessionData = $session['data'] ?? [];

            // Fetch the quote object using the quote ID from checkout session
            $quoteId = $this->checkoutSession->getQuoteId();
            $quote = $this->quoteRepository->get($quoteId);

            // Fetch current billing, shipping, and cart data
            $billingData = $this->billingData->getBillingData($fallbackEmail);
            $shippingData = $this->shippingData->getShippingData($fallbackEmail);
            $cart = $this->cart->getCart();

            // Compare with existing session data
            $billingDataChanged = $this->compareData->compareData($sessionData['billing'] ?? [], $billingData);
            $shippingDataChanged = $this->compareData->compareData($sessionData['shipping'] ?? [], $shippingData);
            $cartTotalCompare = !$this->compareData->doesTotalsMatch($sessionData['order']['amountIncVat'] ?? [], (int) round($quote->getGrandTotal() * 100, 0));

            $this->logger->debug('Starting validation compare  for session '.$session["sessionId"]);
            $this->logger->debug('comparing adresses and totals from session: '.$sessionData['order']['amountIncVat']." and quote ". (int) round($quote->getGrandTotal() * 100, 0));
            $this->logger->debug('billingDataChanged', ['billingDataChanged' => $billingDataChanged]);
            $this->logger->debug('shippingDataChanged', ['shippingDataChanged' => $shippingDataChanged]);
            $this->logger->debug('doesTotalsMatch', ['doesTotalsMatch' => $cartTotalCompare]);

            $validation = true;
            $this->eventManager->dispatch('briqpay_payment_module_decision_prepare', [
                'session' => $session,
                'validation' => &$validation,
                'quote' => $quote
            ]);


            // Perform decision making
            $decision = !$billingDataChanged && !$shippingDataChanged && !$cartTotalCompare && $validation;

            $this->logger->debug('Final Decision', ['Final' => $decision]);
            $this->makeDecision->makeDecision($sessionId, $decision);

            return $decision;
        } catch (\Exception $e) {
            $this->logger->error('Error making decision for session ' . $sessionId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \Exception('Error making decision for session ' . $sessionId);
        }
    }
}
