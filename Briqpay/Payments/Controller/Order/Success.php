<?php

namespace Briqpay\Payments\Controller\Order;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\CreateOrder;
use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Briqpay\Payments\Model\OrderManagement\OrphanedAuthorisation;
use Briqpay\Payments\Model\PaymentModule\ReadSession;

class Success implements HttpGetActionInterface
{
    protected $checkoutSession;
    protected $customerSession;
    protected $createOrder;
    protected $logger;
    protected $quoteRepository;
    protected $sessionManager;
    private RedirectFactory $redirectFactory;
    private ManagerInterface $messageManager;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private ReadSession $readSession;
    private OrphanedAuthorisation $orphanedAuthorisation;

    public function __construct(
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CreateOrder $createOrder,
        Logger $logger,
        CartRepositoryInterface $quoteRepository,
        SessionManagerInterface $sessionManager,
        ManagerInterface $messageManager,
        RedirectFactory $redirectFactory,
        ?OrderRepositoryInterface $orderRepository = null,
        ?SearchCriteriaBuilder $searchCriteriaBuilder = null,
        ?ReadSession $readSession = null,
        ?OrphanedAuthorisation $orphanedAuthorisation = null
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->createOrder = $createOrder;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->sessionManager = $sessionManager;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->orderRepository = $orderRepository
            ?? ObjectManager::getInstance()->get(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $searchCriteriaBuilder
            ?? ObjectManager::getInstance()->get(SearchCriteriaBuilder::class);
        $this->readSession = $readSession ?? ObjectManager::getInstance()->get(ReadSession::class);
        $this->orphanedAuthorisation = $orphanedAuthorisation
            ?? ObjectManager::getInstance()->get(OrphanedAuthorisation::class);
    }

    public function execute()
    {
        // The order webhook may have created the order before the customer got here.
        try {
            $sessionId = $this->briqpaySessionId();
            $quoteId = $this->checkoutSession->getQuote()->getId();
        } catch (\Throwable $e) {
            // Not even the cart could be read (the database, say): the customer gets an
            // answer that does not invite paying twice.
            $this->logger->critical('Could not read the cart on the return to the shop: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(__(
                'Something went wrong while placing your order. If you were charged, the shop will confirm '
                . 'the order or contact you; please do not pay again before you hear from it.'
            ));

            return $this->redirectFactory->create()->setPath('checkout/onepage/failure');
        }
        try {
            $existing = $this->existingOrder($sessionId, $quoteId)
                ?? $this->orderOfSessionsCart($sessionId, $quoteId);
        } catch (\Throwable $e) {
            // The order is looked up again below.
            $this->logger->error('Could not look up the order on the return to the shop: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $existing = null;
        }
        if ($existing !== null) {
            return $this->showSuccess($existing);
        }
        if ($sessionId === null && !$quoteId) {
            // Opened without a checkout (a bookmark, a second tab): nothing to create.
            $this->logger->info('Briqpay success page opened without a checkout.');

            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        try {
            $this->createOrder->createOrder();
            // Redirect to the default order success page

            $this->sessionManager->unsetData('briqpay_session_id');
            $this->sessionManager->unsetData('briqpay_previous_session_ids');

            return $this->redirectFactory->create()->setPath('checkout/onepage/success');
        } catch (\Throwable $e) {
            try {
                return $this->orderNotCreated($e, $sessionId, $quoteId);
            } catch (\Throwable $f) {
                // Even the failure could not be handled (the database, say): the customer
                // still gets an answer that does not invite paying twice.
                $this->logger->critical('Could not handle a failed order on the return to the shop: '
                    . $f->getMessage(), ['exception' => $f, 'cause' => $e->getMessage()]);
                $this->messageManager->addErrorMessage(__(
                    'Something went wrong while placing your order. If you were charged, the shop will confirm '
                    . 'the order or contact you; please do not pay again before you hear from it.'
                ));

                return $this->redirectFactory->create()->setPath('checkout/onepage/failure');
            }
        }
    }

    /**
     * Answers a return to the shop whose order could not be created here: the order the
     * webhook created meanwhile, or the cart or failure page with what the customer must
     * know about the payment.
     *
     * @param \Throwable $e why the order was not created
     * @param string|null $sessionId
     * @param mixed $quoteId
     * @return \Magento\Framework\Controller\ResultInterface
     */
    private function orderNotCreated(\Throwable $e, ?string $sessionId, $quoteId)
    {
        // Or created it while this request was trying to.
        $existing = $this->existingOrder($sessionId, $quoteId);
        if ($existing !== null) {
            $this->logger->info('Order for the Briqpay session was created by its webhook.', [
                'orderIncrementId' => $existing->getIncrementId(),
            ]);

            return $this->showSuccess($existing);
        }

        $quote = $this->checkoutSession->getQuote();
        if ($sessionId === null) {
            // No payment to complete here: the cart was paid elsewhere (another tab, a
            // login that merged it) or never reached Briqpay.
            $this->logger->info('Briqpay success page opened for a cart without a Briqpay session.', [
                'quoteId' => $quote->getId(),
            ]);

            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
        $session = $this->briqpaySession($sessionId);
        if ($session !== null && $quote->getId()
            && $this->orphanedAuthorisation->cancelIfNoOrderPossible($quote, $sessionId, $session)
        ) {
            $this->sessionManager->unsetData('briqpay_session_id');
            $this->messageManager->addErrorMessage(__(
                'Your order could not be completed, for example because an item is no longer available. '
                . 'The payment has been cancelled.'
            ));

            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        // Unset the Briqpay session id on the quote, unless Briqpay holds a payment for
        // it, completed or still being approved (or could not be asked): the order
        // webhook can then still create the order, which it cannot once the quote has
        // lost the session id.
        $paid = $session === null || $this->paymentTaken($session);
        if ($quote->getId() && !$paid) {
            $quote->setData('briqpay_session_id', null);
            $this->quoteRepository->save($quote);
        }
        //Unset Briqpay sessionid on m2 session
        $this->sessionManager->unsetData('briqpay_session_id');
        $orderStatus = $session['moduleStatus']['payment']['orderStatus'] ?? null;
        // A payment that may have been taken with no order is critical; a return
        // from an unpaid session is not.
        $charged = $paid && !in_array($orderStatus, ['order_rejected', 'order_cancelled'], true);
        if ($charged) {
            $this->logger->critical($e->getMessage());
        } else {
            $this->logger->warning('No order for an unpaid Briqpay session: ' . $e->getMessage());
        }
        if ($session === null) {
            $message = __(
                'Something went wrong while placing your order. If you were charged, the shop will confirm '
                . 'the order or contact you; please do not pay again before you hear from it.'
            );
        } elseif ($charged) {
            $message = __(
                'Your payment was received, but the order could not be placed right away. The shop will '
                . 'confirm it or contact you; please do not pay again.'
            );
        } else {
            $message = __('Something went wrong while placing your order. You have not been charged; please try again.');
        }
        $this->messageManager->addErrorMessage($message);
        return $this->redirectFactory->create()->setPath('checkout/onepage/failure');
    }

    /**
     * Whether Briqpay holds a payment on the session: completed, or made and still being
     * approved (an order status, or a payment attempt that was not refused).
     *
     * @param array<string, mixed> $session
     * @return bool
     */
    private function paymentTaken(array $session): bool
    {
        $orderStatus = $session['moduleStatus']['payment']['orderStatus'] ?? null;
        if (in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)) {
            return false;
        }
        if (($session['status'] ?? null) === 'completed' || $orderStatus !== null) {
            return true;
        }
        foreach ((array) ($session['data']['transactions'] ?? []) as $transaction) {
            $status = is_array($transaction) ? ($transaction['status'] ?? null) : null;
            if (!in_array($status, ['rejected', 'cancelled', 'expired', 'failed'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The order of the cart this browser's session pays for, by Briqpay's own record of
     * the session: another tab may have replaced the session the customer paid, and the
     * webhook placed the order from the paid one, leaving the cart inactive. The PHP
     * session cannot carry that across tabs (each request writes it back whole).
     *
     * @param string|null $sessionId
     * @param mixed $quoteId the active cart's id, if any
     * @return OrderInterface|null
     */
    private function orderOfSessionsCart(?string $sessionId, $quoteId): ?OrderInterface
    {
        // With a cart in the browser, its order was looked for already: only an ordered
        // (inactive) cart needs Briqpay's record to be found.
        if ($sessionId === null || $quoteId) {
            return null;
        }
        $session = $this->briqpaySession($sessionId);
        $cartId = (string) ($session['references']['quoteId'] ?? '');
        if ($cartId === '') {
            return null;
        }

        return $this->existingOrder(null, $cartId);
    }

    /**
     * The Briqpay session the customer paid: the PHP session's or the quote's. When they
     * differ (two sessions were started for the cart), the completed one is the payment,
     * and the order is created from it.
     */
    private function briqpaySessionId(): ?string
    {
        // The current ids first, then any session the checkout replaced on the way:
        // the customer may have paid that one (another tab) before the shop moved on.
        $previous = array_map('strval', (array) ($this->sessionManager->getData('briqpay_previous_session_ids') ?? []));
        $candidates = array_values(array_unique(array_filter(array_merge([
            (string) $this->sessionManager->getData('briqpay_session_id'),
            (string) $this->checkoutSession->getQuote()->getData('briqpay_session_id'),
        ], array_reverse($previous)))));
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) > 1) {
            // A session an order was already placed from (by its webhook, while the
            // customer was away) is the payment, whatever the cart now holds.
            $quoteId = (string) $this->checkoutSession->getQuote()->getId();
            foreach ($candidates as $candidate) {
                $held = $this->existingOrder($candidate, null);
                if ($held !== null && ($quoteId === '' || (string) $held->getQuoteId() === $quoteId)) {
                    $this->sessionManager->setData('briqpay_session_id', $candidate);

                    return $candidate;
                }
            }
            // The cart is gone once ordered or merged at login: then any completed one.
            foreach ($candidates as $candidate) {
                $session = $this->briqpaySession($candidate);
                // Only a session Briqpay records as this cart's: the list outlives a purchase.
                if (($session['status'] ?? null) === 'completed'
                    && ($quoteId === '' || (string) ($session['references']['quoteId'] ?? '') === $quoteId)
                ) {
                    $this->sessionManager->setData('briqpay_session_id', $candidate);

                    return $candidate;
                }
            }
        }

        return $candidates[0];
    }

    /**
     * @return array<string, mixed>|null the session, or null when there is none or it could not be read
     */
    private function briqpaySession(?string $sessionId): ?array
    {
        if ($sessionId === null) {
            return null;
        }
        try {
            return (array) $this->readSession->getSession($sessionId);
        } catch (Exception $e) {
            $this->logger->error('Could not read the Briqpay session on the return to the shop: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The order for the Briqpay session, or for the quote: an order the webhook created
     * may not carry the session id yet.
     *
     * @param string|null $sessionId
     * @param int|string|null $quoteId
     * @return OrderInterface|null
     */
    private function existingOrder(?string $sessionId, $quoteId): ?OrderInterface
    {
        $filters = [];
        if ($sessionId !== null) {
            $filters[] = ['briqpay_session_id', $sessionId];
        }
        if ($quoteId) {
            $filters[] = ['quote_id', $quoteId];
        }
        foreach ($filters as [$field, $value]) {
            $criteria = $this->searchCriteriaBuilder->addFilter($field, $value)->setPageSize(1)->create();
            $orders = $this->orderRepository->getList($criteria)->getItems();
            $order = reset($orders);
            if ($order) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Shows Magento's success page for an order, as checkout does after placing it.
     */
    private function showSuccess(OrderInterface $order): \Magento\Framework\Controller\Result\Redirect
    {
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getEntityId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastOrderStatus($order->getStatus());
        $this->sessionManager->unsetData('briqpay_session_id');
        $this->sessionManager->unsetData('briqpay_previous_session_ids');

        return $this->redirectFactory->create()->setPath('checkout/onepage/success');
    }
}
