<?php

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\HostedPage\CreatePaymentLink;
use Magento\Sales\Api\OrderRepositoryInterface;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\Utility\AsyncCreateOrder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Briqpay\Payments\Model\OrderManagement\OrphanedAuthorisation;

class HandleWebhook
{
    private ?SessionOrderData $sessionOrderData;

    protected $logger;
    protected $orderRepository;
    protected $quoteRepository;
    protected $createOrder;
    protected $readSession;
    protected $asyncCreateOrder;
    protected $orderManagement;
    protected $searchCriteriaBuilder;
    private OrphanedAuthorisation $orphanedAuthorisation;
    private ScopeHelper $scopeHelper;
    private \Magento\Framework\Lock\LockManagerInterface $lockManager;

    private \Magento\Quote\Model\ResourceModel\Quote $quoteResource;

    /** Briqpay's order status and the Magento state and status it means. */
    private const STATUS_MAPPINGS = [
        'order_approved_not_captured' => ['state' => Order::STATE_PROCESSING, 'status' => Order::STATE_PROCESSING],
        'order_pending' => ['state' => Order::STATE_NEW, 'status' => 'pending'],
        'order_rejected' => ['state' => Order::STATE_CANCELED, 'status' => 'canceled'],
        'order_cancelled' => ['state' => Order::STATE_CANCELED, 'status' => 'canceled'],
        'captured_full' => ['state' => Order::STATE_PROCESSING, 'status' => Order::STATE_PROCESSING],
        // Magento has no state distinct from "processing" for a partially captured
        // order, so this mirrors captured_full.
        'captured_partial' => ['state' => Order::STATE_PROCESSING, 'status' => Order::STATE_PROCESSING],
    ];

    public function __construct(
        Logger $logger,
        OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $quoteRepository,
        ReadSession $readSession,
        CreateOrder $createOrder,
        AsyncCreateOrder $asyncCreateOrder,
        OrderManagementInterface $orderManagement,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ?OrphanedAuthorisation $orphanedAuthorisation = null,
        ?ScopeHelper $scopeHelper = null,
        ?SessionOrderData $sessionOrderData = null,
        ?\Magento\Framework\Lock\LockManagerInterface $lockManager = null,
        ?\Magento\Quote\Model\ResourceModel\Quote $quoteResource = null
    ) {
        $this->quoteResource = $quoteResource
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Quote\Model\ResourceModel\Quote::class);
        $this->sessionOrderData = $sessionOrderData;
        $this->lockManager = $lockManager
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Framework\Lock\LockManagerInterface::class);
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->quoteRepository = $quoteRepository;
        $this->readSession = $readSession;
        $this->createOrder = $createOrder;
        $this->asyncCreateOrder = $asyncCreateOrder;
        $this->orderManagement = $orderManagement;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orphanedAuthorisation = $orphanedAuthorisation
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(OrphanedAuthorisation::class);
        $this->scopeHelper = $scopeHelper
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(ScopeHelper::class);
    }

    public function isQuoteConvertedToOrder($quote)
    {
        return !$quote->getIsActive();
    }
    public function processOrderStatusWebhook(array $data): array
    {
        return $this->inQuoteStore($data['quoteId'] ?? null, function () use ($data): array {
            return $this->processInStore($data);
        }, (string) ($data['sessionId'] ?? ''));
    }

    /**
     * Runs $handle with the settings of the quote's store: a webhook arrives on whichever
     * store view the shop's base URL resolves to (see ScopeHelper::inStore()).
     *
     * @param mixed $quoteId
     * @param callable $handle
     * @return array<string, mixed>
     */
    private function inQuoteStore($quoteId, callable $handle, string $sessionId = ''): array
    {
        try {
            // The stored store id; getStoreId() would fall back to the current store.
            $storeId = $this->quoteRepository->get($quoteId)->getData('store_id');
        } catch (\Exception $e) {
            // The quote may be gone (Magento removes old carts); the order holds the session.
            $order = $sessionId !== '' ? $this->orderBySession($sessionId) : null;
            $storeId = $order !== null ? $order->getStoreId() : null;
        }

        return $this->scopeHelper->inStore($storeId, $handle);
    }

    /**
     * The order that holds the session, for an order whose quote Magento has removed.
     *
     * @param string $sessionId
     * @return Order|null
     */
    private function orderBySession(string $sessionId): ?Order
    {
        if ($sessionId === '') {
            return null;
        }
        $criteria = $this->searchCriteriaBuilder->addFilter('briqpay_session_id', $sessionId, 'eq')->create();
        $result = $this->orderRepository->getList($criteria);
        $orders = $result ? $result->getItems() : [];
        $order = reset($orders);

        return $order instanceof Order && $order->getEntityId()
            && (string) $order->getData('briqpay_session_id') === $sessionId ? $order : null;
    }

    /**
     * The order placed for the quote, whatever session paid it.
     *
     * @param int|string $quoteId
     * @return Order|null
     */
    private function orderForQuote($quoteId): ?Order
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('quote_id', $quoteId, 'eq')->create();
        $result = $this->orderRepository->getList($criteria);
        $orders = $result ? $result->getItems() : [];
        $order = reset($orders);

        return $order instanceof Order && $order->getEntityId() && (string) $order->getQuoteId() === (string) $quoteId
            ? $order
            : null;
    }

    /**
     * A completed session that pays for this cart (Briqpay's record says so) but is no
     * longer the one the cart holds: the shop replaced it (a changed company or
     * currency, another tab) while this one was being paid. The money moved, so the
     * webhook is never dropped: it reaches the order placed from this session, or
     * places the order, or is noted on the order another session paid for.
     *
     * @param mixed $quote
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleReplacedSession($quote, array $session): array
    {
        $sessionId = (string) ($session['sessionId'] ?? '');
        $orderStatus = (string) ($session['moduleStatus']['payment']['orderStatus'] ?? '');
        $holder = $this->orderBySession($sessionId);
        if ($holder !== null) {
            // Placed from this session on the customer's return; the cart moved on since.
            if (isset(self::STATUS_MAPPINGS[$orderStatus])) {
                $briqpaySessionStatus = (string) ($session['data']['transactions'][0]['status'] ?? '');
                $this->moveLocked($holder, $orderStatus, $briqpaySessionStatus, $session);
            }
            return ['status' => true];
        }
        if (in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)) {
            return ['status' => true];
        }
        $existing = $this->orderForQuote($quote->getId());
        if ($existing !== null) {
            // The cart was ordered through another session: the customer may have paid twice.
            $this->notePaymentOnReplacedSession($existing, $sessionId);
            return ['status' => true];
        }
        if ($quote->getIsActive()) {
            // No order anywhere for a paid cart: this session is the payment, and the
            // one the cart holds is unpaid. The cart takes this session back and is ordered.
            $this->logger->warning('A paid session the cart no longer held is taken back; the order is created from it.', [
                'quoteId' => $quote->getId(),
                'sessionId' => $sessionId,
                'replacedBy' => (string) $quote->getData('briqpay_session_id'),
            ]);
            $this->storeSessionOnQuote($quote, $sessionId);
            $this->createOrderOrCancel($quote->getId(), $session);
            return ['status' => true];
        }
        if (!$this->reportPaymentOnReplacedSession($quote, $session)) {
            // Nothing in Magento can take this payment: the record is the log, and a
            // retry could not do better.
            $this->logger->critical('A completed session has no order and the shop could do nothing with it.', [
                'quoteId' => $quote->getId(),
                'sessionId' => $sessionId,
            ]);
        }

        return ['status' => true];
    }

    /**
     * Writes the session id on the quote as a column: a repository save would re-price
     * and re-validate a cart that may have been converted long ago.
     *
     * @param mixed $quote
     * @param string $sessionId
     * @return void
     */
    private function storeSessionOnQuote($quote, string $sessionId): void
    {
        $quote->setData('briqpay_session_id', $sessionId);
        $this->quoteResource->getConnection()->update(
            $this->quoteResource->getMainTable(),
            ['briqpay_session_id' => $sessionId],
            ['entity_id = ?' => (int) $quote->getId()]
        );
    }

    /**
     * A webhook for an order whose quote is gone (an admin order paid through its link
     * after Magento removed old carts): the order itself holds the session.
     *
     * @param array<string, mixed> $data
     * @param \Magento\Framework\Exception\NoSuchEntityException $missingQuote
     * @return array<string, mixed>
     * @throws \Magento\Framework\Exception\NoSuchEntityException when no order holds the session either
     */
    private function processWithoutQuote(
        array $data,
        \Magento\Framework\Exception\NoSuchEntityException $missingQuote
    ): array {
        $sessionId = (string) ($data['sessionId'] ?? '');
        if (($data['event'] ?? null) !== 'order_status') {
            $this->logger->warning('Unknown event type: ' . (string) ($data['event'] ?? ''));
            return ['status' => true];
        }
        $order = $this->orderBySession($sessionId);
        if ($order === null) {
            $this->reportReplacedLinkWithoutQuote($sessionId, $missingQuote);
            return ['status' => true];
        }
        $session = $this->readSession->getSession($sessionId);
        if (!self::sessionNames($session, (string) $order->getIncrementId())) {
            $this->logger->warning('HandleWebhook: the session does not name the order that holds it - skipped.', [
                'orderIncrementId' => $order->getIncrementId(),
                'sessionId' => $sessionId,
            ]);
            return ['status' => true];
        }
        if (($session['status'] ?? null) !== 'completed') {
            $this->logger->warning('HandleWebhook: session status is not "completed" - order status update skipped.', [
                'orderIncrementId' => $order->getIncrementId(),
                'sessionId' => $sessionId,
            ]);
            return ['status' => true];
        }
        $orderStatus = (string) ($session['moduleStatus']['payment']['orderStatus'] ?? '');
        if (!isset(self::STATUS_MAPPINGS[$orderStatus])) {
            $this->logger->warning('Unknown order status: ' . $orderStatus);
            return ['status' => true];
        }
        $this->logger->info('The order\'s quote is gone; the webhook is applied to the order itself.', [
            'orderIncrementId' => $order->getIncrementId(),
            'sessionId' => $sessionId,
        ]);
        $briqpaySessionStatus = (string) ($session['data']['transactions'][0]['status'] ?? '');
        $this->moveLocked($order, $orderStatus, $briqpaySessionStatus, $session);

        return ['status' => true];
    }

    /**
     * Whether Briqpay's record of the session names the order (a payment link's orderId,
     * or the checkout's reference1).
     *
     * @param array<string, mixed> $session
     * @param string $incrementId
     * @return bool
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure read of the session, never intercepted
    private static function sessionNames(array $session, string $incrementId): bool
    {
        $references = $session['references'] ?? [];

        return in_array($incrementId, [(string) ($references['orderId'] ?? ''), (string) ($references['reference1'] ?? '')], true);
    }

    /**
     * No quote and no order holds the session: a payment link that was replaced, paid
     * after Magento removed the cart. The session itself names the order, which gets
     * the "paid twice" note; anything else is reported as the missing quote.
     *
     * @param string $sessionId
     * @param \Magento\Framework\Exception\NoSuchEntityException $missingQuote
     * @return void
     */
    private function reportReplacedLinkWithoutQuote(
        string $sessionId,
        \Magento\Framework\Exception\NoSuchEntityException $missingQuote
    ): void {
        $session = $this->readSession->getSession($sessionId);
        if (($session['status'] ?? null) !== 'completed') {
            // Not a payment: the missing cart is all there is to say.
            $this->logger->warning('Webhook for a session without a cart or an order: ' . $missingQuote->getMessage(), [
                'sessionId' => $sessionId,
            ]);
            return;
        }
        $references = $session['references'] ?? [];
        foreach ([(string) ($references['orderId'] ?? ''), (string) ($references['reference1'] ?? '')] as $incrementId) {
            if ($incrementId === '') {
                continue;
            }
            $criteria = $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId, 'eq')->create();
            $result = $this->orderRepository->getList($criteria);
            $orders = $result ? $result->getItems() : [];
            $order = reset($orders);
            if ($order instanceof Order && $order->getEntityId()) {
                $this->notePaymentOnReplacedSession($order, $sessionId);
                return;
            }
        }
        // A payment with no cart and no order anywhere: nothing in Magento can take it,
        // and a retry could not do better. The record is this line.
        $this->logger->critical('A completed session has neither a cart nor an order in Magento.', [
            'sessionId' => $sessionId,
            'reason' => $missingQuote->getMessage(),
        ]);
    }

    /**
     * Moves the order under the session's lock, on a fresh copy: the capture webhook of
     * an auto-capture payment arrives at the same time and saves the same order.
     *
     * @param Order $order
     * @param string $orderStatus
     * @param string $briqpaySessionStatus
     * @param array<string, mixed> $session
     * @return void
     */
    private function moveLocked(Order $order, string $orderStatus, string $briqpaySessionStatus, array $session): void
    {
        $name = 'briqpay_order_' . (string) ($session['sessionId'] ?? $order->getData('briqpay_session_id'));
        if (!$this->lockManager->lock($name, 20)) {
            throw new \RuntimeException('Another request is updating the order for this session.');
        }
        try {
            $fresh = $this->orderRepository->get((int) $order->getEntityId());
            $this->moveOrder(
                $fresh instanceof Order ? $fresh : $order,
                $orderStatus,
                self::STATUS_MAPPINGS[$orderStatus],
                $briqpaySessionStatus,
                $session
            );
        } finally {
            $this->lockManager->unlock($name);
        }
    }

    /**
     * See processOrderStatusWebhook().
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function processInStore(array $data): array
    {
        $quoteId = $data['quoteId'] ?? null;
        try {
            try {
                $quote = $this->quoteRepository->get($quoteId);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                return $this->processWithoutQuote($data, $e);
            }

            $event = $data['event'] ?? null;
            $sessionId = $data['sessionId'] ?? null;

            // The session, read from the Briqpay API, says which quote it pays for.
            $session = $this->readSession->getSession($sessionId);
            $sessionQuoteId = $session['references']['quoteId'] ?? null;
            if ($sessionQuoteId === null || (string) $sessionQuoteId !== (string) $quote->getId()) {
                $this->logger->warning('HandleWebhook: the session was not created for this quote - skipped.', [
                    'quoteId' => $quoteId,
                    'sessionId' => $sessionId,
                    'sessionQuoteId' => $sessionQuoteId,
                    'event' => $event,
                ]);
                return ['status' => true];
            }

            // A quote whose session id was cleared (its authorisation cancelled) takes a
            // session back only while that session is a completed, live payment.
            $orderStatusNow = $session['moduleStatus']['payment']['orderStatus'] ?? null;
            if (is_null($quote->getData('briqpay_session_id'))
                && ($session['status'] ?? null) === 'completed'
                && !in_array($orderStatusNow, ['order_rejected', 'order_cancelled'], true)
            ) {
                $this->storeSessionOnQuote($quote, (string) $sessionId);
            }

            if (!isset($session['status']) || $session['status'] !== 'completed') {
                // The session is not completed yet, so the order is not updated; logged
                // as a warning so the skipped update can be traced.
                $this->logger->warning('HandleWebhook: session status is not "completed" - order status update skipped.', [
                    'quoteId' => $quoteId,
                    'sessionId' => $sessionId,
                    'event' => $event,
                    'sessionStatus' => $session['status'] ?? null,
                ]);
                return ['status' => true];
            }

            // Ensure quote session matches
            $quoteSession = $quote->getData('briqpay_session_id');
            if ($quoteSession !== $sessionId) {
                // Logged with both session ids, so the skipped update can be traced.
                $this->logger->warning('HandleWebhook: quote\'s stored briqpay_session_id does not match the webhook\'s sessionId - order status update skipped.', [
                    'quoteId' => $quoteId,
                    'quoteSessionId' => $quoteSession,
                    'webhookSessionId' => $sessionId,
                    'event' => $event,
                ]);
                return $this->handleReplacedSession($quote, $session);
            }

            // Get transaction status
            $transactions = $session['data']['transactions'] ?? [];
            $briqpaySessionStatus = (string) ($transactions[0]['status'] ?? '');

            if ($event === 'order_status') {
                $orderStatus = $session['moduleStatus']['payment']['orderStatus'] ?? null;
                switch ($orderStatus) {
                    case 'order_approved_not_captured':
                    case 'captured_full':
                    case 'captured_partial':
                    case 'order_pending':
                    case 'order_rejected':
                    case 'order_cancelled':
                        $this->handleOrderStatus($quote, $orderStatus, $briqpaySessionStatus, $quoteId, $session);
                        break;

                    default:
                        $this->logger->warning('Unknown order status: ' . $orderStatus);
                        break;
                }
            } else {
                $this->logger->warning('Unknown event type: ' . $event);
            }

            return ['status' => true];
        } catch (\Exception $e) {
            $this->logger->error('Error processing webhook for Quote ID: ' . $quoteId . '. Error: ' . $e->getMessage());
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    protected function handleOrderStatus($quote, $orderStatus, $briqpaySessionStatus, $quoteId, $session)
    {
        try {
            $reservedOrderId = $quote->getReservedOrderId();
            if ($reservedOrderId) {
                // Fetch order by increment ID
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $reservedOrderId, 'eq')
                    ->create();
                $orders = $this->orderRepository->getList($searchCriteria)->getItems();
                $order = reset($orders);
                if (!$order || !$order->getEntityId()
                    || ($order->getQuoteId() && (string) $order->getQuoteId() !== (string) $quote->getId())
                ) {
                    // Magento may have numbered the order anew when the reserved number
                    // was taken (by another cart's order); the quote still names it.
                    $order = $this->orderForQuote($quoteId);
                }

                $orderSession = $order && $order->getEntityId() ? (string) $order->getData('briqpay_session_id') : '';
                if ($orderSession !== '' && $orderSession !== (string) ($session['sessionId'] ?? '')) {
                    // The cart was ordered from another session (one the checkout had
                    // replaced): this payment is a second one, never applied to that order.
                    if (!in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)) {
                        $this->notePaymentOnReplacedSession($order, (string) ($session['sessionId'] ?? ''));
                    }
                } elseif ($order && $order->getEntityId()) {
                    $this->moveLocked($order, $orderStatus, $briqpaySessionStatus, $session);
                } elseif (!in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)) {
                    $this->logger->debug('Order entity ID not found for Quote ID: ' . $quoteId);
                    $this->createOrderOrCancel($quoteId, $session);
                }
            } elseif (!in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)) {
                $this->logger->debug('No reserved order ID found for Quote ID: ' . $quoteId);
                $this->createOrderOrCancel($quoteId, $session);
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->logger->error('Order with reserved order ID ' . $reservedOrderId . ' not found.');
            throw new \Exception('Order with reserved order ID ' . $reservedOrderId . ' not found.');
        } catch (\Exception $e) {
            $this->logger->error('Error handling order status for Quote ID: ' . $quoteId . '. Error: ' . $e->getMessage());
            throw new \Exception('Error handling order status for Quote ID: ' . $quoteId . '. Error: ' . $e->getMessage());
        }
    }

    /**
     * Moves an existing order along with Briqpay's order status, only ever forward from
     * its pending states. An order Magento holds (OrderCreationGuard, an admin), has
     * completed, closed or cancelled keeps its state: the webhook may be a repeat or a
     * late retry. A rejected or cancelled payment cancels a pending order through
     * Magento, so stock and totals follow; an order Magento cannot cancel is noted.
     *
     * @param Order $order
     * @param string $orderStatus
     * @param array{state:string,status:string} $mapped
     * @param string $briqpaySessionStatus
     * @param array<string, mixed> $session
     * @return void
     */
    private function moveOrder(
        Order $order,
        string $orderStatus,
        array $mapped,
        string $briqpaySessionStatus,
        array $session = []
    ): void {
        $pending = in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true);
        if (in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)) {
            if ($order->getState() === Order::STATE_CANCELED) {
                return;
            }
            if ($pending && $this->orderManagement->cancel((int) $order->getEntityId())) {
                $this->logger->info('Order ID: ' . $order->getEntityId() . ' cancelled: Briqpay reports ' . $orderStatus);
                return;
            }
            if (!$this->noteOnce($order, (string) __(
                'Briqpay reports the payment as %1 (rejected or cancelled), but the order could not be cancelled '
                . 'in Magento. Cancel it by hand if nothing was invoiced.',
                $orderStatus
            ))) {
                return;
            }
            $this->orderRepository->save($order);
            $this->logger->warning('Order ID: ' . $order->getEntityId() . ' not cancelled although Briqpay reports '
                . $orderStatus . '; its state is ' . $order->getState());
            return;
        }
        if (!$pending) {
            $paidStatus = in_array($orderStatus, ['order_pending', 'order_approved_not_captured'], true);
            if ($paidStatus && $session !== [] && CreatePaymentLink::placedInAdmin($order)
                && (CreatePaymentLink::unpaid($order) || $order->getState() === Order::STATE_HOLDED)
            ) {
                $this->reportLinkPaidLate($order, $session, $orderStatus, $mapped, $briqpaySessionStatus);
                return;
            }
            $this->logger->debug('Order ID: ' . $order->getEntityId() . ' kept in state ' . $order->getState()
                . ' on Briqpay status ' . $orderStatus);
            return;
        }
        if ($order->getState() !== $mapped['state']) {
            if ((string) $order->getData('briqpay_psp_display_name') === '' && $session !== []) {
                // An order paid after it was created (a payment link): the session's
                // payment method, reservation and backoffice link are written now.
                $this->sessionOrderData()->apply($order, $session);
                if (CreatePaymentLink::placedInAdmin($order)) {
                    $order->addCommentToStatusHistory((string) __(
                        'Paid through the Briqpay payment link with %1.',
                        (string) ($order->getData('briqpay_psp_display_name') ?: 'Briqpay')
                    ));
                }
            }
            $order->setState($mapped['state']);
            $order->setStatus($mapped['status']);
            $order->setData('briqpay_session_status', $briqpaySessionStatus);
            $this->orderRepository->save($order);
            $this->logger->info('Order ID: ' . $order->getEntityId() . ' status updated to ' . $orderStatus . '.');
        }
    }

    /**
     * A payment link paid after its order left the pending states: invoiced offline
     * (Processing already; the payment's details are written to it, and the merchant
     * must capture the authorisation at Briqpay), or held, cancelled or closed (left
     * as it is). Either way the merchant must see it.
     *
     * @param Order $order
     * @param array<string, mixed> $session
     * @param string $orderStatus
     * @param array{state: string, status: string} $mapped
     * @param string $briqpaySessionStatus
     * @return void
     */
    private function reportLinkPaidLate(
        Order $order,
        array $session,
        string $orderStatus,
        array $mapped,
        string $briqpaySessionStatus
    ): void {
        // The session status on the order makes a repeat of this webhook a no-op, and
        // lets a later cancel release the authorisation.
        $firstReport = CreatePaymentLink::unpaid($order);
        $order->setData('briqpay_session_status', $briqpaySessionStatus);
        if ($order->getState() === Order::STATE_HOLDED) {
            // The hold is kept; removing it lands the order where the latest webhook
            // would have put it, with the payment's details already on it.
            $this->sessionOrderData()->apply($order, $session);
            $order->setData('hold_before_state', $mapped['state']);
            $order->setData('hold_before_status', $mapped['status']);
            $this->noteOnce($order, (string) __(
                'Briqpay reports a payment on the link (%1). The order is on hold: the payment\'s details were '
                . 'added, and it continues as %2 when the hold is released.',
                $orderStatus,
                self::stateLabel($mapped['state'])
            ));
            $this->orderRepository->save($order);
            if ($firstReport) {
                $this->logger->error('A payment link was paid on an order that is on hold.', [
                    'orderIncrementId' => $order->getIncrementId(),
                ]);
            }
            return;
        }
        $captured = false;
        foreach ((array) ($session['data']['captures'] ?? []) as $capture) {
            $captured = $captured || (is_array($capture) && ($capture['status'] ?? null) === 'approved');
        }
        if ((float) $order->getTotalInvoiced() > 0 && $captured) {
            // An auto-capture method's capture webhook invoiced the order before this
            // webhook (Processing, or Complete for a virtual order): nothing is wrong.
            $this->sessionOrderData()->apply($order, $session);
            $order->addCommentToStatusHistory((string) __(
                'Paid through the Briqpay payment link with %1.',
                (string) ($order->getData('briqpay_psp_display_name') ?: 'Briqpay')
            ));
            $this->orderRepository->save($order);
            return;
        }
        if ($order->getState() === $mapped['state']) {
            // Invoiced offline: Magento thinks it is paid, but nothing captured the
            // payment and no invoice is left to capture it with.
            $this->sessionOrderData()->apply($order, $session);
            $order->addCommentToStatusHistory((string) __(
                'Briqpay reports a payment on the link (%1), but the order is already %2 (invoiced offline?), '
                . 'so no invoice is left to capture it with. Capture it in the Briqpay backoffice once Briqpay '
                . 'has approved it.',
                $orderStatus,
                self::stateLabel((string) $order->getState())
            ));
        } else {
            $order->addCommentToStatusHistory((string) __(
                'Briqpay reports a payment on the link (%1), but the order is %2 and was left as it is. '
                . 'Check the Briqpay backoffice.',
                $orderStatus,
                self::stateLabel((string) $order->getState())
            ));
        }
        $this->logger->error('A payment link was paid on an order that no longer waits for it.', [
            'orderIncrementId' => $order->getIncrementId(),
            'state' => $order->getState(),
        ]);
        $this->orderRepository->save($order);
    }

    /**
     * A completed session that is no longer the quote's (a payment link replaced by a
     * new session): the customer paid, and the merchant must see it on the order.
     *
     * @param mixed $quote
     * @param array<string, mixed> $session
     * @return bool
     */
    private function reportPaymentOnReplacedSession($quote, array $session): bool
    {
        $incrementId = (string) ($quote->getReservedOrderId() ?? '');
        if (($session['status'] ?? null) !== 'completed' || $incrementId === '') {
            return false;
        }
        $criteria = $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId, 'eq')->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($orders);
        if (!$order || !$order->getEntityId()) {
            return false;
        }
        $this->notePaymentOnReplacedSession($order, (string) ($session['sessionId'] ?? ''));

        return true;
    }

    /**
     * @param Order $order
     * @param string $sessionId
     * @return void
     */
    private function notePaymentOnReplacedSession(Order $order, string $sessionId): void
    {
        $noted = $this->noteOnce($order, (string) __(
            'Briqpay reports a payment on session %1, which is not this order\'s current session (an earlier '
            . 'payment link or checkout session). Check the Briqpay backoffice: the customer may have paid twice.',
            $sessionId
        ));
        if (!$noted) {
            return;
        }
        $this->logger->error('A payment was made on a session that is no longer the order\'s.', [
            'orderIncrementId' => $order->getIncrementId(),
            'sessionId' => $sessionId,
        ]);
        $this->orderRepository->save($order);
    }

    /**
     * A Magento order state as the merchant sees it.
     *
     * @param string $state
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure text helper, never intercepted
    private static function stateLabel(string $state): string
    {
        $labels = [
            Order::STATE_NEW => 'Pending',
            Order::STATE_PENDING_PAYMENT => 'Pending Payment',
            Order::STATE_PROCESSING => 'Processing',
            Order::STATE_COMPLETE => 'Complete',
            Order::STATE_CLOSED => 'Closed',
            Order::STATE_CANCELED => 'Cancelled',
            Order::STATE_HOLDED => 'On Hold',
            Order::STATE_PAYMENT_REVIEW => 'Payment Review',
        ];

        return $labels[$state] ?? ucfirst(str_replace('_', ' ', $state));
    }

    /**
     * Adds the note unless it is the one Briqpay's last update already left: a repeated
     * or retried webhook adds nothing. The last note is remembered on the payment.
     *
     * @param Order $order
     * @param string $note
     * @return bool whether the note was added
     */
    private function noteOnce(Order $order, string $note): bool
    {
        $payment = $order->getPayment();
        $key = hash('sha256', $note);
        if ($payment !== null && $payment->getAdditionalInformation('briqpay_last_webhook_note') === $key) {
            return false;
        }
        if ($payment !== null) {
            $payment->setAdditionalInformation('briqpay_last_webhook_note', $key);
        }
        $order->addCommentToStatusHistory($note);

        return true;
    }

    /**
     * Creates the order for a paid session, or, when Magento cannot create it, cancels
     * the authorisation so the customer is not charged without an order.
     *
     * @param int|string $quoteId
     * @param array<string, mixed> $session
     * @return void
     * @throws \Exception when the order could not be created and the authorisation was left as it is
     */
    private function createOrderOrCancel($quoteId, array $session): void
    {
        try {
            $this->asyncCreateOrder->createOrderFromWebhook($quoteId, $session);
        } catch (\Exception $e) {
            $quote = $this->quoteRepository->get($quoteId);
            if ($this->orphanedAuthorisation->cancelIfNoOrderPossible($quote, (string) $session['sessionId'], $session)) {
                return;
            }
            throw $e;
        }
    }

    private function sessionOrderData(): SessionOrderData
    {
        $this->sessionOrderData = $this->sessionOrderData
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(SessionOrderData::class);

        return $this->sessionOrderData;
    }
}
