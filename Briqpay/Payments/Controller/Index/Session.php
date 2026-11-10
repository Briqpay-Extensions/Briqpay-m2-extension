<?php

namespace Briqpay\Payments\Controller\Index;

use Briqpay\Payments\Rest\ApiRequestException;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Briqpay\Payments\Model\Utility\ReinitialiseSession;
use Briqpay\Payments\Model\PaymentModule\CreateSession;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\PaymentModule\UpdateReference;
use Briqpay\Payments\Model\Utility\CompanyName;
use Briqpay\Payments\Logger\Logger;
use Magento\Quote\Api\CartRepositoryInterface;
use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\OrderManagement\OrphanedAuthorisation;

class Session extends Action
{
    protected $resultJsonFactory;
    protected $createSession;
    protected $reinitialiseSession;
    protected $checkoutSession;
    protected $readSession;
    protected $logger;
    protected $sessionManager;
    protected $quoteRepository;
    private SetupConfig $setupConfig;
    private OrphanedAuthorisation $orphanedAuthorisation;
    private \Magento\Framework\Lock\LockManagerInterface $lockManager;
    private \Magento\Quote\Model\ResourceModel\Quote $quoteResource;
    private UpdateReference $updateReference;

    /**
     * Whether the page's notice that its payment ended was handled; null without one.
     */
    private ?bool $endRecorded = null;

    /**
     * Whether this request ended the page's approval on the session.
     */
    private bool $endedHere = false;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CreateSession $createSession,
        ReinitialiseSession $reinitialiseSession,
        CheckoutSession $checkoutSession,
        ReadSession $readSession,
        Logger $logger,
        SessionManagerInterface $sessionManager,
        CartRepositoryInterface $quoteRepository,
        ?SetupConfig $setupConfig = null,
        ?OrphanedAuthorisation $orphanedAuthorisation = null,
        ?\Magento\Framework\Lock\LockManagerInterface $lockManager = null,
        ?\Magento\Quote\Model\ResourceModel\Quote $quoteResource = null,
        ?UpdateReference $updateReference = null
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->createSession = $createSession;
        $this->reinitialiseSession = $reinitialiseSession;
        $this->checkoutSession = $checkoutSession;
        $this->readSession = $readSession;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
        $this->quoteRepository = $quoteRepository;
        $this->setupConfig = $setupConfig ?? \Magento\Framework\App\ObjectManager::getInstance()->get(SetupConfig::class);
        $this->orphanedAuthorisation = $orphanedAuthorisation
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(OrphanedAuthorisation::class);
        $this->lockManager = $lockManager
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Framework\Lock\LockManagerInterface::class);
        $this->quoteResource = $quoteResource
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Quote\Model\ResourceModel\Quote::class);
        $this->updateReference = $updateReference
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(UpdateReference::class);
    }

    /**
     * The session id stored on the quote, read from the database now.
     *
     * A request for the same cart may just have stored one (the lock in execute() made
     * this request wait for it); the quote object this request loaded before waiting,
     * and its own PHP session, would still show none or an older one.
     *
     * @param int|string|null $quoteId
     * @return string|null
     */
    private function storedSessionId($quoteId): ?string
    {
        if (!$quoteId) {
            return null;
        }
        $connection = $this->quoteResource->getConnection();
        $stored = $connection->fetchOne(
            $connection->select()
                ->from($this->quoteResource->getMainTable(), ['briqpay_session_id'])
                ->where('entity_id = ?', $quoteId)
        );

        return $stored ? (string) $stored : null;
    }

    public function execute()
    {
        // The checkout can ask for the session twice at once (a totals refresh while the
        // first request runs); the second must find the first's session, not start another.
        $lock = 'briqpay_checkout_' . $this->checkoutSession->getQuoteId();
        if (!$this->lockManager->lock($lock, 20)) {
            $this->logger->error('Briqpay checkout session: another request for the quote did not finish.');

            return $this->getErrorResponse();
        }
        try {
            return $this->startSession();
        } finally {
            $this->lockManager->unlock($lock);
        }
    }

    /**
     * Creates the quote's Briqpay session, or updates the one it has.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function startSession()
    {
        $guestEmail = $this->guestEmail();

        try {
            $sessionId = $this->sessionManager->getData('briqpay_session_id');
            $quoteId = $this->checkoutSession->getQuoteId();
            $stored = $this->storedSessionId($quoteId);
            if ($stored !== null && $stored !== $sessionId) {
                $sessionId = $stored;
                $this->sessionManager->start();
                $this->sessionManager->setData('briqpay_session_id', $stored);
            }

            $triggerNewSession = false;
            if ($sessionId) {
                try {
                    $readSessionData = $this->endedDecision(
                        (string) $sessionId,
                        $this->readSession->getSession($sessionId)
                    );
                } catch (\Exception $e) {
                    if (!ApiRequestException::refusedIn($e)) {
                        throw $e;
                    }
                    // Briqpay does not know the stored session under these credentials
                    // (the shop went live, the secret changed, the session expired): it
                    // is forgotten and a new one started, rather than this cart never
                    // paying again.
                    $this->logger->warning('Stored Briqpay session cannot be read; starting a new one.', [
                        'sessionId' => $sessionId,
                    ]);
                    $this->forgetSession($quoteId);
                    $sessionId = null;
                    $triggerNewSession = true;
                }
            }

            if ($sessionId && ($quoteId != $readSessionData['references']['quoteId'])) {
                $this->logger->info('Quotes did not match, starting new session');
                $triggerNewSession = true;
                // A session of an earlier cart: it is not this cart's to remember, and
                // nothing remembered for the earlier cart belongs to this one.
                $sessionId = null;
                $this->sessionManager->setData('briqpay_previous_session_ids', []);
            }
            if ($sessionId && !$triggerNewSession && $this->paidButWithoutOrder($readSessionData, $sessionId)) {
                $triggerNewSession = true;
            }
            if ($sessionId && !$triggerNewSession && !$this->returnsToThisShop($readSessionData)) {
                $triggerNewSession = true;
            }
            // A completed session is never replaced for its data: the customer paid it,
            // and its order is still to be created (or is being created). Nor is one with
            // a payment attempt on it: the payment may be running in another tab.
            $completed = $sessionId && $this->paymentUnderWay($readSessionData);
            if ($sessionId && !$triggerNewSession && !$completed && $this->companyChanged($readSessionData)) {
                $triggerNewSession = true;
            }
            if ($sessionId && !$triggerNewSession && !$completed && $this->setupChanged($readSessionData)) {
                $triggerNewSession = true;
            }

            if ($sessionId && !$triggerNewSession && $this->decisionRunning($readSessionData)
                && ($readSessionData['status'] ?? null) !== 'completed'
                && is_string($readSessionData['htmlSnippet'] ?? null)
            ) {
                // A purchase on this session was approved moments ago and is being paid
                // (in this tab or another): it is shown as it is and not updated, locked
                // or replaced while the payment runs.
                $session = ['htmlSnippet' => $readSessionData['htmlSnippet']];
                $activeSessionId = (string) $sessionId;
                $paymentRunning = true;
            } elseif ($sessionId && !$triggerNewSession) {
                // After the page's payment ended, the session is sent again even when
                // nothing changed, so the next purchase on it is decided afresh.
                $session = $this->endedHere
                    ? $this->reinitialiseSession->sendAgain($sessionId, $guestEmail)
                    : $this->reinitialiseSession->reinitialiseSession($sessionId, $guestEmail);
                $activeSessionId = (string) $sessionId;
            } else {
                $quoteId = $this->checkoutSession->getQuoteId();
                $this->quoteRepository->get($quoteId);

                $briqpaySessionId = $triggerNewSession ? null : $this->storedSessionId($quoteId);
                if (!is_null($briqpaySessionId) && !$triggerNewSession
                    && !$this->returnsToThisShop((array) $this->readSession->getSession($briqpaySessionId))
                ) {
                    $triggerNewSession = true;
                }
                if (!is_null($briqpaySessionId) && !$triggerNewSession) {
                    $session = $this->reinitialiseSession->reinitialiseSession($briqpaySessionId, $guestEmail);
                    $activeSessionId = (string) $briqpaySessionId;
                    $this->sessionManager->start();
                    $this->sessionManager->setData('briqpay_session_id', $briqpaySessionId);
                } else {
                    $session = $this->createSession->getPaymentModule($guestEmail);
                    $activeSessionId = (string) $session['sessionId'];
                    $this->sessionManager->start();
                    $this->rememberReplaced((string) ($sessionId ?: $briqpaySessionId));
                    $this->sessionManager->setData('briqpay_session_id', $session['sessionId']);
                }
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // A checkout opened without a cart (an expired one, a second tab after
            // paying): nothing to start, and nothing wrong.
            $this->logger->info('Briqpay session requested without a cart: ' . $e->getMessage());
            return $this->getErrorResponse();
        } catch (\Throwable $e) {
            $this->logger->error('Exception occurred: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $this->getErrorResponse();
        }

        return $this->getSuccessResponse($session['htmlSnippet'], $activeSessionId, $paymentRunning ?? false);
    }

    /**
     * Drops the session id from the PHP session and the quote.
     *
     * @param int|string|null $quoteId
     * @return void
     */
    private function forgetSession($quoteId): void
    {
        $this->sessionManager->start();
        $this->sessionManager->setData('briqpay_session_id', null);
        if ($quoteId) {
            $connection = $this->quoteResource->getConnection();
            $connection->update(
                $this->quoteResource->getMainTable(),
                ['briqpay_session_id' => null],
                ['entity_id = ?' => $quoteId]
            );
        }
    }

    private function getErrorResponse()
    {
        return $this->resultJsonFactory->create()->setData([
            'error' => true,
            'message' => (string) __('The payment window could not be loaded. Reload the page or choose another payment method.'),
        ]);
    }

    /**
     * The snippet and the id of the session it renders: the page swaps the payment
     * window when the shop started a new session for the cart.
     *
     * @param string $htmlSnippet
     * @param string $sessionId
     * @param bool $paymentRunning whether a purchase on the session is being paid
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function getSuccessResponse($htmlSnippet, string $sessionId, bool $paymentRunning = false)
    {
        $htmlSnippet = $this->removeScriptTag($htmlSnippet);
        return $this->resultJsonFactory->create()->setData([
            'message' => $htmlSnippet,
            'sessionId' => $sessionId,
            'paymentRunning' => $paymentRunning,
            'paymentEnded' => $this->endRecorded,
        ]);
    }

    private function removeScriptTag($htmlSnippet)
    {
        // Remove the <script> tag from the HTML snippet
        $htmlSnippet = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $htmlSnippet);
        return $htmlSnippet;
    }

    /**
     * Whether a completed session has no order and never will: Magento could not place
     * it (see OrphanedAuthorisation), so the payment was cancelled and the customer can
     * pay again in a new session. A completed session whose order exists, or may still
     * be created, is left as it is.
     *
     * @param array<string, mixed> $session
     * @param string $sessionId
     * @return bool
     */
    private function paidButWithoutOrder(array $session, string $sessionId): bool
    {
        if (($session['status'] ?? null) !== 'completed') {
            return false;
        }
        $quote = $this->quoteRepository->get($this->checkoutSession->getQuoteId());
        if ($this->storedSessionId($quote->getId()) !== $sessionId) {
            // Cancelled earlier, by the order webhook or the return to the shop.
            return true;
        }
        if ($this->orphanedAuthorisation->cancelIfNoOrderPossible($quote, $sessionId, $session)) {
            return true;
        }
        $this->logger->warning('Briqpay session is completed; the checkout cannot start a new one.', [
            'sessionId' => $sessionId,
            'quoteId' => $quote->getId(),
        ]);

        return false;
    }

    /**
     * Whether a business session's company is no longer the cart's billing company: a
     * billing company entered or changed after the session was created (a guest's, on
     * the payment step) needs a new session.
     *
     * @param array<string, mixed> $session
     * @return bool
     */
    private function companyChanged(array $session): bool
    {
        if (($this->setupConfig->getSetupConfig()['customer_type'] ?? '') !== 'business') {
            return false;
        }
        $quote = $this->quoteRepository->get($this->checkoutSession->getQuoteId());
        // A session that names the cart's company is never replaced for it.
        if (!CompanyName::changedSince($quote, $session)) {
            return false;
        }
        $this->logger->info('The billing company changed since the Briqpay session was created; starting a new one.');

        return true;
    }

    /**
     * Keeps the id of a session this cart leaves behind: the customer may have paid it
     * meanwhile (another tab), and the return to the shop looks at it too.
     *
     * @param string $sessionId
     * @return void
     */
    private function rememberReplaced(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }
        $previous = (array) ($this->sessionManager->getData('briqpay_previous_session_ids') ?? []);
        if (!in_array($sessionId, $previous, true)) {
            $previous[] = $sessionId;
        }
        $this->sessionManager->setData('briqpay_previous_session_ids', array_slice($previous, -5));
    }

    /**
     * Whether a purchase on the session was approved recently and may still be paid, in
     * this tab or another.
     *
     * @param array<string, mixed> $session
     * @return bool
     */
    private function decisionRunning(array $session): bool
    {
        return UpdateReference::decisionRunning($session);
    }

    /**
     * The tab whose purchase was approved says when that payment ended without an order
     * (the customer cancelled it): the approval is cleared at Briqpay, so this and every
     * later request bring the session up to date. Only the session the tab
     * itself paid is cleared, and only the approval it got (a later one stays). The answer
     * says whether it was recorded, and the page repeats the notice until it was.
     *
     * @param string $sessionId
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function endedDecision(string $sessionId, array $session): array
    {
        $ended = $this->paymentEnded();
        if ($ended === null) {
            return $session;
        }
        $this->endRecorded = true;
        [$endedSession, $endedApproval] = array_pad(explode('|', $ended, 2), 2, '');
        if ($endedSession !== $sessionId || !$this->decisionRunning($session)
            || $endedApproval !== (string) ($session['references']['decidedAt'] ?? '')
        ) {
            return $session;
        }
        try {
            $this->updateReference->endDecision($sessionId, (array) $session['references']);
            $session['references']['decidedAt'] = '0';
            $this->endedHere = true;
        } catch (\Exception $e) {
            $this->endRecorded = false;
            $this->logger->warning('Could not mark the ended payment on the Briqpay session.', [
                'sessionId' => $sessionId,
                'exception' => $e,
            ]);
        }

        return $session;
    }

    /**
     * The payment the page says ended without an order ("session|approval"), or null.
     *
     * @return string|null
     */
    private function paymentEnded(): ?string
    {
        $request = $this->getRequest();
        $body = $request instanceof \Magento\Framework\App\Request\Http
            ? json_decode((string) $request->getContent(), true)
            : null;
        $ended = is_array($body) ? ($body['paymentEnded'] ?? null) : null;

        return is_string($ended) && $ended !== '' ? $ended : null;
    }

    /**
     * Whether the session holds a payment, or one being made: completed, or with a
     * transaction or order status that is not rejected or cancelled. A refused attempt
     * does not keep the session; the customer may change what was refused.
     *
     * @param array<string, mixed> $session
     * @return bool
     */
    private function paymentUnderWay(array $session): bool
    {
        if ($this->decisionRunning($session)) {
            return true;
        }
        // A live attempt first: a retry after a refused one keeps the session.
        foreach ((array) ($session['data']['transactions'] ?? []) as $transaction) {
            if (!in_array($transaction['status'] ?? null, ['rejected', 'cancelled', 'expired', 'failed'], true)) {
                return true;
            }
        }
        $orderStatus = $session['moduleStatus']['payment']['orderStatus'] ?? null;
        if (in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)) {
            return false;
        }

        return ($session['status'] ?? null) === 'completed' || $orderStatus !== null;
    }

    private function guestEmail(): ?string
    {
        $request = $this->getRequest();
        $body = $request instanceof \Magento\Framework\App\Request\Http
            ? json_decode((string) $request->getContent(), true)
            : null;
        if (is_array($body) && isset($body['guestEmail']) && is_string($body['guestEmail'])) {
            return $body['guestEmail'] !== '' ? $body['guestEmail'] : null;
        }
        $hash = $this->getRequest()->getParam('hash');
        if (!is_string($hash) || $hash === '') {
            return null;
        }
        $email = base64_decode($hash, true);

        return $email !== false && $email !== '' ? $email : null;
    }

    /**
     * Whether the session was created for another currency or country than the cart
     * pays in now (a currency switched in the checkout, a store set up anew): the cart
     * needs a new session.
     *
     * @param array<string, mixed> $session
     * @return bool
     */
    private function setupChanged(array $session): bool
    {
        // The store's current currency and country, which the cart pays in.
        $config = $this->setupConfig->getSetupConfig();
        $currency = strtoupper((string) ($config['currency'] ?? ''));
        $sessionCurrency = strtoupper((string) ($session['data']['order']['currency'] ?? ''));
        $country = strtoupper((string) ($config['country'] ?? ''));
        $sessionCountry = strtoupper((string) ($session['country'] ?? ''));
        if (($sessionCurrency === '' || $currency === '' || $sessionCurrency === $currency)
            && ($sessionCountry === '' || $country === '' || $sessionCountry === $country)
        ) {
            return false;
        }
        $this->logger->info('The currency or country changed since the Briqpay session was created; starting a new one.');

        return true;
    }

    /**
     * @param array<string, mixed> $session
     * @return bool
     */
    private function returnsToThisShop(array $session): bool
    {
        $redirect = $session['urls']['redirect'] ?? null;
        $current = $this->setupConfig->getSetupConfig()['redirect_url'] ?? null;
        if (!is_string($redirect) || !is_string($current) || $redirect === $current) {
            return true;
        }
        $this->logger->info('Briqpay session returns to another shop address, starting a new session.');

        return false;
    }
}
