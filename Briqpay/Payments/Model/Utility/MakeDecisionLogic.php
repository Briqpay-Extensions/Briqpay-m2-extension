<?php

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\CompareData;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\PaymentModule\MakeDecision;
use Briqpay\Payments\Model\PaymentModule\UpdateReference;
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
    protected $updateReference;
    protected $compareData;
    protected $logger;
    protected $eventManager;
    protected $quoteRepository;
    protected $checkoutSession;
    protected $paymentCompositeChecks;
    private OrderBlockers $orderBlockers;

    public function __construct(
        AssignBillingAddress $billingData,
        AssignShippingAddress $shippingData,
        GenerateCart $cart,
        ReadSession $readSession,
        MakeDecision $makeDecision,
        UpdateReference $updateReference,
        CompareData $compareData,
        Logger $logger,
        EventManager $eventManager,
        QuoteRepository $quoteRepository,
        CheckoutSession $checkoutSession,
        PaymentCompositeChecks $paymentCompositeChecks,
        ?OrderBlockers $orderBlockers = null,
        ?\Magento\Framework\Lock\LockManagerInterface $lockManager = null,
        ?\Briqpay\Payments\Model\Config\SetupConfig $setupConfig = null
    ) {
        $this->setupConfig = $setupConfig
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Briqpay\Payments\Model\Config\SetupConfig::class);
        $this->lockManager = $lockManager
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Framework\Lock\LockManagerInterface::class);
        $this->billingData = $billingData;
        $this->shippingData = $shippingData;
        $this->cart = $cart;
        $this->readSession = $readSession;
        $this->makeDecision = $makeDecision;
        $this->updateReference = $updateReference;
        $this->compareData = $compareData;
        $this->logger = $logger;
        $this->eventManager = $eventManager;
        $this->quoteRepository = $quoteRepository;
        $this->checkoutSession = $checkoutSession;
        $this->paymentCompositeChecks = $paymentCompositeChecks;
        $this->orderBlockers = $orderBlockers
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(OrderBlockers::class);
    }

    private \Magento\Framework\Lock\LockManagerInterface $lockManager;

    private \Briqpay\Payments\Model\Config\SetupConfig $setupConfig;

    /**
     * Sets the payment method, and a missing guest email, on the quote before it is
     * validated and ordered.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string|null $fallbackEmail
     * @return void
     */
    private function claimQuote($quote, $fallbackEmail): void
    {
        $changed = false;
        if ($quote->getPayment()->getMethod() !== 'briqpay') {
            $quote->getPayment()->setMethod('briqpay');
            $changed = true;
        }
        if (!$quote->getCustomerEmail() && $fallbackEmail) {
            $quote->setCustomerEmail($fallbackEmail);
            if ($quote->getBillingAddress() && !$quote->getBillingAddress()->getEmail()) {
                $quote->getBillingAddress()->setEmail($fallbackEmail);
            }
            $changed = true;
        }
        if ($changed) {
            $this->quoteRepository->save($quote);
        }
    }

    /**
     * The approval the page's earlier purchase on the session got (its decidedAt), sent
     * with a new try; null for a page that has none.
     */
    private ?string $approval = null;

    /**
     * The approval this decision gave, for the page to send with its next try.
     */
    private ?string $given = null;

    /**
     * Why the last makeDecision() refused, for the page, when the customer must act.
     */
    private ?string $refusal = null;

    /**
     * Why the last makeDecision() refused, when the customer must do something about it.
     *
     * @return string|null
     */
    public function refusalForCustomer(): ?string
    {
        return $this->refusal;
    }

    /**
     * Names the approval the page making this decision got earlier, if any.
     *
     * @param string|null $approval
     * @return $this
     */
    public function withApproval(?string $approval): self
    {
        $this->approval = $approval !== null && $approval !== '' ? $approval : null;

        return $this;
    }

    /**
     * The approval the last makeDecision() gave, or null.
     *
     * @return string|null
     */
    public function givenApproval(): ?string
    {
        return $this->given;
    }

    public function makeDecision(string $sessionId, $fallbackEmail = null): bool
    {
        $this->given = null;
        $this->refusal = null;
        // The same lock the checkout's session request takes: the cart's session cannot
        // be replaced between this check and the decision Briqpay acts on.
        $lock = 'briqpay_checkout_' . $this->checkoutSession->getQuoteId();
        if (!$this->lockManager->lock($lock, 20)) {
            $this->logger->warning('Decision refused: another request for the cart did not finish.', ['sessionId' => $sessionId]);

            return false;
        }
        try {
            return $this->decide($sessionId, $fallbackEmail);
        } finally {
            $this->lockManager->unlock($lock);
        }
    }

    /**
     * @param string $sessionId
     * @param string|null $fallbackEmail
     * @return bool
     */
    private function decide(string $sessionId, $fallbackEmail): bool
    {
        $decision = true; // Start with the assumption that the decision will be true
        $validationErrors = []; // Array to hold validation error messages
        $softError = false; // Initialize softError to false
        $session = [];
        $marked = false; // whether this decision marked the session as being paid
    
        try {
            // Fetch current session data
            $session = $this->readSession->getSession($sessionId);
            $sessionData = $session['data'] ?? [];
    
            // Fetch the quote object using the quote ID from checkout session
            $quoteId = $this->checkoutSession->getQuoteId();
            $quote = $this->quoteRepository->get($quoteId);
    
            // Validate required quote fields before proceeding
            if (!$quote->getCustomerEmail() && !$fallbackEmail) {
                $validationErrors[] = 'Customer email is missing.';
                $softError = true; // Set softError to true if an error is found
            }
    
            if (!$quote->getBillingAddress()) {
                $validationErrors[] = 'Billing address is missing.';
                $softError = true; // Set softError to true if an error is found
            }
    
            if (!$quote->getIsVirtual() && !$quote->getShippingAddress()) {
                $validationErrors[] = 'Shipping address is missing.';
                $softError = true; // Set softError to true if an error is found
            }

            // The checkout replaced this session (another tab, a changed company): a
            // payment on it would have no order. The customer reloads and pays the new one.
            $current = (string) $quote->getData('briqpay_session_id');
            if ($current !== '' && $current !== $sessionId) {
                // Nothing is sent to Briqpay for a session the cart left; the page
                // refreshes and shows the cart's current one.
                $this->logger->warning('Decision refused: the session is no longer the cart\'s current one.', [
                    'sessionId' => $sessionId,
                    'currentSessionId' => $current,
                ]);

                return false;
            }
            // Only Briqpay's own record says which cart a session pays for; a session
            // made for another cart (a payment link's, say) is never claimed for this one.
            $sessionQuoteId = (string) ($session['references']['quoteId'] ?? '');
            if ($sessionQuoteId !== (string) $quoteId) {
                // Nothing is sent to Briqpay: a refusal would end the payment of the
                // cart the session does belong to.
                $this->logger->warning('Decision refused: the session was not created for this cart.', [
                    'sessionId' => $sessionId,
                    'sessionQuoteId' => $sessionQuoteId,
                    'quoteId' => $quoteId,
                ]);

                return false;
            }
    
            // A purchase on this session was approved moments ago and may still be being
            // paid. Only the page that started it may try again (its payment ended without
            // an order); any other page is told to finish or cancel that payment first, and
            // nothing changes for it. The mark runs out after the decision window.
            $running = UpdateReference::decisionRunning($session);
            $own = $running && $this->approval !== null
                && $this->approval === (string) ($session['references']['decidedAt'] ?? '');
            if ($running && !$own) {
                $this->logger->warning('Decision refused: a payment on the session is in progress in another window.', [
                    'sessionId' => $sessionId,
                ]);
                // Nothing is posted to Briqpay: a refusal there could end the payment
                // running in the other window. The page shows why.
                $this->refusal = (string) __(
                    'A payment for this cart is already in progress in another window. Finish or cancel it there, '
                    . 'or try again in %1 minutes.',
                    (int) ceil(UpdateReference::DECISION_WINDOW / 60)
                );

                return false;
            }
            if ($own) {
                // This page's earlier payment ended without an order: the mark goes, and
                // the session, left as it was while that payment ran, is checked below.
                $this->endDecision($sessionId, (array) ($session['references'] ?? []));
                $session['references']['decidedAt'] = '0';
            }

            // The customer pressed pay in the Briqpay checkout, so the quote pays with
            // Briqpay and, for a guest, has the email the checkout sent along: a virtual
            // cart has no shipping step that would have saved either.
            $this->claimQuote($quote, $fallbackEmail);

            foreach ($this->orderBlockers->forQuote($quote) as $blocker) {
                $validationErrors[] = $blocker;
            }

            // Apply Magento's core payment method checks
            $paymentMethod = $quote->getPayment()->getMethodInstance();
            $checksResult = $this->paymentCompositeChecks->isApplicable($paymentMethod, $quote);
    
            if (!$checksResult) {
                // Which of Magento's checks failed is not reported, so the inputs they use are logged.
                $this->logger->warning('Payment method validation failed for session ' . $sessionId
                    . ': the country, currency or order total is not allowed for Briqpay', [
                        'country' => $quote->getBillingAddress() ? $quote->getBillingAddress()->getCountryId() : null,
                        'currency' => $quote->getQuoteCurrencyCode(),
                        'grandTotal' => $quote->getBaseGrandTotal(),
                    ]);
                return false; // If validation fails, return false immediately
            }
    
            // Fetch current billing, shipping, and cart data
            $billingData = $this->billingData->getBillingData($fallbackEmail);
            $shippingData = $this->shippingData->getShippingData($fallbackEmail);
            $cart = $this->cart->getCart();
    
            // Compare with existing session data
            $billingDataChanged = $this->compareData->compareData($sessionData['billing'] ?? [], $billingData);
            // A virtual quote has no shipping address, on the session or the quote.
            $shippingDataChanged = $shippingData !== null
                && $this->compareData->compareData($sessionData['shipping'] ?? [], $shippingData);
            $sessionAmountIncVat = (int) ($sessionData['order']['amountIncVat'] ?? 0);
            $quoteAmountIncVat = (int) round($quote->getGrandTotal() * 100, 0);
            $cartTotalCompare = !$this->compareData->doesTotalsMatch($sessionAmountIncVat, $quoteAmountIncVat);

            // Log the results of the comparisons
            if ($billingDataChanged) {
                $validationErrors[] = 'Billing data has changed.';
            }

            if ($shippingDataChanged) {
                $validationErrors[] = 'Shipping data has changed.';
            }

            // This page's earlier approval left the session as it was then (the checkout
            // does not update a session that may be being paid): a cart changed since, at
            // the same total, is caught here.
            if ($own && $this->compareData->linesDiffer($sessionData['order']['cart'] ?? [], $cart)) {
                $validationErrors[] = 'Cart lines changed since an earlier approval of the session.';
            }

            // Briqpay keeps a business session's company from its creation; the checkout
            // replaces the session when the company changes, except while a payment on
            // it may still be running. A purchase is never approved for another company.
            if (($this->setupConfig->getSetupConfig()['customer_type'] ?? '') === 'business'
                && CompanyName::changedSince($quote, $session)
            ) {
                $validationErrors[] = 'The billing company changed since the session was created.';
            }

            if ($cartTotalCompare) {
                // Both amounts, so the log tells a stale session from a changed cart.
                $validationErrors[] = sprintf(
                    'Cart totals do not match. session.amountIncVat=%d quote.grandTotal(minor)=%d diff=%d',
                    $sessionAmountIncVat,
                    $quoteAmountIncVat,
                    $quoteAmountIncVat - $sessionAmountIncVat
                );
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
                // A refusal the customer recovers from: the page brings the session up to
                // date and the next try can pass.
                foreach ($validationErrors as $error) {
                    $this->logger->warning('Validation error for session ' . $sessionId . ': ' . $error);
                }
                $decision = false; // If any validation errors exist, set decision to false
            }

            $this->logger->debug('Final Decision', ['Final' => $decision]);

            if ($decision) {
                // The approval's token is the page's from here on: whatever Briqpay
                // answers below, the session may now be marked with it, and the page
                // must be able to continue or end that payment.
                $this->given = (string) time();
                $this->updateReference->updateReference(
                    $sessionId,
                    (int) $quoteId,
                    (array) ($session['references'] ?? []),
                    $this->given
                );
                $marked = true;
            }

            $this->makeDecision->makeDecision($sessionId, $decision, $softError); // Pass softError as the third param
    
            return $decision;
        } catch (\Throwable $e) {
            $this->logger->error('Error making decision for session ' . $sessionId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            // Briqpay refused the approval (or the mark): no payment follows, so the mark
            // goes. When it did not answer, it may have taken them, and the mark stays,
            // with its token on the page.
            $status = \Briqpay\Payments\Rest\ApiRequestException::statusIn($e);
            // A conflict (a decision made already), a timeout or a rate limit says nothing
            // about whether the payment goes ahead: the mark stays.
            if ($this->given !== null && $status !== null && $status >= 400 && $status < 500
                && !in_array($status, [408, 409, 429], true)
            ) {
                if ($marked) {
                    $this->endDecision($sessionId, $this->updateReference->written());
                }
                $this->given = null;
            }
            return false; // If an exception occurs, decision should be false
        }
    }

    /**
     * Clears the session's mark of an approved purchase; a failure is logged, and the
     * mark then runs out by itself.
     *
     * @param string $sessionId
     * @param array<string, mixed> $references
     * @return void
     */
    private function endDecision(string $sessionId, array $references): void
    {
        try {
            $this->updateReference->endDecision($sessionId, $references);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not clear the approval mark on the Briqpay session.', [
                'sessionId' => $sessionId,
                'exception' => $e,
            ]);
        }
    }
}
