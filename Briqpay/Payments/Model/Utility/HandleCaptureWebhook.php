<?php

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Api\OrderRepositoryInterface;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\Utility\AsyncCreateOrder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Briqpay\Payments\Model\CustomTableFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Lock\LockManagerInterface;
use Briqpay\Payments\Model\OrderManagement\AutoCaptureInvoicer;

class HandleCaptureWebhook
{
    protected $logger;
    protected $orderRepository;
    protected $quoteRepository;
    protected $createOrder;
    protected $readSession;
    protected $asyncCreateOrder;
    protected $orderManagement;
    protected $searchCriteriaBuilder;
    protected $customTableFactory;
    private InvoiceService $invoiceService;
    private TransactionFactory $transactionFactory;
    private ScopeHelper $scopeHelper;
    private LockManagerInterface $lockManager;
    private AutoCaptureInvoicer $autoCaptureInvoicer;

    public function __construct(
        Logger $logger,
        OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $quoteRepository,
        ReadSession $readSession,
        CreateOrder $createOrder,
        AsyncCreateOrder $asyncCreateOrder,
        OrderManagementInterface $orderManagement,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CustomTableFactory $customTableFactory,
        ?InvoiceService $invoiceService = null,
        ?TransactionFactory $transactionFactory = null,
        ?ScopeHelper $scopeHelper = null,
        ?LockManagerInterface $lockManager = null,
        ?AutoCaptureInvoicer $autoCaptureInvoicer = null
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->quoteRepository = $quoteRepository;
        $this->readSession = $readSession;
        $this->createOrder = $createOrder;
        $this->asyncCreateOrder = $asyncCreateOrder;
        $this->orderManagement = $orderManagement;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->customTableFactory = $customTableFactory;
        $this->invoiceService = $invoiceService ?? ObjectManager::getInstance()->get(InvoiceService::class);
        $this->transactionFactory = $transactionFactory
            ?? ObjectManager::getInstance()->get(TransactionFactory::class);
        $this->scopeHelper = $scopeHelper ?? ObjectManager::getInstance()->get(ScopeHelper::class);
        $this->lockManager = $lockManager ?? ObjectManager::getInstance()->get(LockManagerInterface::class);
        $this->autoCaptureInvoicer = $autoCaptureInvoicer ?? new AutoCaptureInvoicer(
            $logger,
            $orderRepository,
            $searchCriteriaBuilder,
            $customTableFactory,
            $this->invoiceService,
            $this->transactionFactory,
            $this->lockManager
        );
    }

    public function processCaptureStatusWebhook(array $data): array
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
     * The cart's own order: by its reserved number when that order is this cart's, else
     * by the cart (Magento numbered it anew because another order took the number).
     * Never another cart's order; null while the cart has none yet.
     *
     * @param mixed $reservedOrderId
     * @param mixed $quote
     * @return Order|null
     */
    private function cartOrder($reservedOrderId, $quote): ?Order
    {
        $byNumber = $reservedOrderId ? $this->orderByIncrementId((string) $reservedOrderId) : null;
        if ($byNumber === null || $quote === null) {
            return $byNumber ?? ($quote !== null ? $this->orderForQuote($quote->getId()) : null);
        }
        if ($byNumber->getQuoteId() && (string) $byNumber->getQuoteId() !== (string) $quote->getId()) {
            return $this->orderForQuote($quote->getId());
        }

        return $byNumber;
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
     * Adds the note unless it is the one Briqpay's last capture update already left: a
     * repeated or retried webhook adds nothing.
     *
     * @param Order $order
     * @param string $note
     * @return bool whether the note was added
     */
    private function noteOnce(Order $order, string $note): bool
    {
        $payment = $order->getPayment();
        $key = hash('sha256', $note);
        if ($payment !== null && $payment->getAdditionalInformation('briqpay_last_capture_note') === $key) {
            return false;
        }
        if ($payment !== null) {
            $payment->setAdditionalInformation('briqpay_last_capture_note', $key);
        }
        $order->addCommentToStatusHistory($note);

        return true;
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
     * See processCaptureStatusWebhook().
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function processInStore(array $data): array
    {
        $quoteId = $data['quoteId'] ?? null;
        try {
            $sessionId = $data['sessionId'] ?? null;
            $reservedOrderId = null;
            $order = null;
            try {
                $quote = $this->quoteRepository->get($quoteId);
                $reservedOrderId = $quote->getReservedOrderId();
                $quoteSession = $quote->getData('briqpay_session_id');
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                // The quote is gone (Magento removes old carts); the order holds the session.
                $order = $this->orderBySession((string) $sessionId);
                if ($order === null) {
                    // Nothing in Magento can take this capture; the order webhook reports
                    // the payment itself, and a retry could not do better.
                    $this->logger->critical('Capture webhook for a session with neither a cart nor an order in Magento.', [
                        'sessionId' => $sessionId,
                        'quoteId' => $quoteId,
                    ]);
                    return ['status' => true];
                }
                $quoteSession = $order->getData('briqpay_session_id');
            }

            $event = $data['event'] ?? null;
            $autoCapture = $data['autoCaptured'] ?? null;
            $isPreExistingCapture = $data['isPreExistingCapture'] ?? null;
            $hookCaptureId = $data["captureId"] ?? null;
            // Get session data
            $session = $this->readSession->getSession($sessionId);

            if (!isset($session['status']) || $session['status'] !== 'completed') {
                $this->logger->warning('Session status is not completed.');
                return ['status' => true];
            }
            if ($order !== null) {
                $this->logger->info('The order\'s quote is gone; the capture webhook is applied to the order itself.', [
                    'orderIncrementId' => $order->getIncrementId(),
                    'sessionId' => $sessionId,
                ]);
            }
            if ($order !== null && !self::sessionNames($session, (string) $order->getIncrementId())) {
                // The order found through the session it holds, with no quote left: Briqpay's
                // own record of the session must name that order.
                $this->logger->warning('Capture webhook: the session does not name the order that holds it - skipped.', [
                    'orderIncrementId' => $order->getIncrementId(),
                    'sessionId' => $sessionId,
                ]);
                return ['status' => true];
            }

            // Ensure quote session matches
            if ($quoteSession !== $sessionId) {
                $holder = $this->orderBySession((string) $sessionId);
                if ($holder === null) {
                    // Only Briqpay's own record says which cart the session pays for.
                    $namesCart = isset($quote)
                        && (string) ($session['references']['quoteId'] ?? '') === (string) $quote->getId();
                    $paidFor = $namesCart ? $this->orderForQuote($quote->getId()) : null;
                    if ($paidFor !== null) {
                        // The cart was ordered through another session: the merchant sees
                        // the capture on that order and checks the backoffice.
                        if ($this->noteOnce($paidFor, (string) __(
                            'Briqpay reports a capture on session %1, which is not this order\'s current session '
                            . '(an earlier payment link or checkout session). Check the Briqpay backoffice: the '
                            . 'customer may have paid twice.',
                            (string) $sessionId
                        ))) {
                            $this->orderRepository->save($paidFor);
                        }
                        return ['status' => true];
                    }
                    if ($namesCart && $quote->getIsActive()) {
                        // The order webhook places the order for a paid session the cart no
                        // longer holds; this capture is retried by Briqpay once it exists.
                        $this->logger->warning('Capture webhook for a session the cart no longer holds and no order holds yet.', [
                            'quoteId' => $quoteId,
                            'sessionId' => $sessionId,
                        ]);
                        return ['status' => false, 'message' => 'No order holds the session yet.'];
                    }
                    $this->logger->critical('Capture webhook for a paid session that no order in Magento holds.', [
                        'quoteId' => $quoteId,
                        'sessionId' => $sessionId,
                    ]);
                    return ['status' => true];
                }
                $order = $holder;
            }

            // Get transaction status
            $transactions = $session['data']['captures'] ?? [];
            $foundCorrectCapture = null;
            foreach ($transactions as $capture) {
                if (isset($capture['captureId']) && $capture['captureId'] === $hookCaptureId) {
                    $foundCorrectCapture = $capture;
                    break;
                }
            }
            
            // Check if the transaction was found and handle it
            if (!$foundCorrectCapture) {
                if ($autoCapture) {
                    return ['status' => true];
                }
                // A capture webhook may name a capture the session does not list (a
                // refused one, say). The merchant is told on the order; nothing was captured.
                $order = $order ?? $this->cartOrder($reservedOrderId, $quote ?? null);
                if ($order !== null) {
                    $this->logger->warning('Capture webhook for a capture Briqpay does not hold.', [
                        'orderId' => $order->getIncrementId(),
                        'captureId' => $hookCaptureId,
                        'status' => $data['status'] ?? null,
                    ]);
                    // A refused capture may come without an id.
                    $noted = $this->noteOnce($order, $hookCaptureId
                        ? (string) __(
                            'Briqpay reports capture %1 as "%2" but holds no such capture, so nothing was captured. Check the invoice in the Briqpay backoffice.',
                            (string) $hookCaptureId,
                            (string) ($data['status'] ?? '')
                        )
                        : (string) __(
                            'Briqpay reports a capture as "%1" that it does not hold, so nothing was captured. Check the invoice in the Briqpay backoffice.',
                            (string) ($data['status'] ?? '')
                        ));
                    if ($noted) {
                        $this->orderRepository->save($order);
                    }

                    return ['status' => true];
                }

                return ['status' => false, 'message' => 'Received a capture webhook that does not match the session'];
            }

            $captureStatus = $foundCorrectCapture["status"];

            $order = $order ?? $this->cartOrder($reservedOrderId, $quote ?? null);
            $target = $order;
            $targetSession = $target !== null ? (string) $target->getData('briqpay_session_id') : '';
            if ($targetSession !== '' && $targetSession !== (string) $sessionId) {
                // The order was placed from another session: this capture is a second
                // payment, noted for the merchant and never invoiced on that order.
                if ($this->noteOnce($target, (string) __(
                    'Briqpay reports a capture on session %1, which is not this order\'s current session '
                    . '(an earlier payment link or checkout session). Check the Briqpay backoffice: the '
                    . 'customer may have paid twice.',
                    (string) $sessionId
                ))) {
                    $this->orderRepository->save($target);
                }
                return ['status' => true];
            }

            if ($event === 'capture_status') {
                switch ($captureStatus) {
                    case 'pending':
                    case 'approved':
                    case 'rejected':
                        $this->handleCaptureStatus(
                            $order,
                            $captureStatus,
                            $hookCaptureId,
                            $session
                        );
                        break;

                    default:
                        $this->logger->warning('Unknown order status: ' . $captureStatus);
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

    /**
     * Brings Magento in line with a capture's status at Briqpay.
     *
     * A capture made from a Magento invoice is found through payment_capture_mapping;
     * approved marks the invoice paid, rejected adds a comment for the merchant. A capture
     * the payment method made by itself (auto-capture) has no invoice yet, so one is
     * created offline for the whole order when the capture covers it.
     *
     * @param Order|null $order
     * @param string $captureStatus
     * @param string $captureId
     * @param array<string, mixed> $session
     * @return void
     * @throws \Exception when the order or the capture's invoice cannot be found
     */
    protected function handleCaptureStatus(?Order $order, $captureStatus, $captureId, array $session = [])
    {
        if ($order === null) {
            throw new \Exception('Order not found for the capture webhook.');
        }

        $collection = $this->customTableFactory->create()->getCollection();
        $collection->addFieldToFilter('capture_id', $captureId);
        $invoiceId = (int) $collection->getFirstItem()->getInvoiceId();

        if ($invoiceId === 0) {
            $transaction = $session['data']['transactions'][0] ?? [];
            if ($captureStatus === 'approved' && !empty($transaction['autoCaptureEnabled'])) {
                // Under the session's lock: the order webhook of the same payment arrives
                // at the same time and saves the same order.
                $lock = 'briqpay_order_' . (string) ($session['sessionId'] ?? $captureId);
                if (!$this->lockManager->lock($lock, 20)) {
                    throw new \Exception('Another request is updating the order for this session.');
                }
                try {
                    $this->autoCaptureInvoicer->invoiceOnce($order, $captureId, $session);
                } finally {
                    $this->lockManager->unlock($lock);
                }

                return;
            }
            $this->logger->info('No invoice in Magento for the capture yet.', [
                'orderId' => $order->getIncrementId(),
                'captureId' => $captureId,
                'captureStatus' => $captureStatus,
            ]);
            if ($captureStatus === 'approved' && !$order->getTotalInvoiced()) {
                // A capture made outside Magento (the Briqpay portal): the merchant sees it
                // on the order, and invoices offline.
                if ($this->noteOnce($order, (string) __(
                    'Briqpay reports capture %1, which was not made from a Magento invoice. '
                    . 'Create the invoice offline if it covers this order.',
                    $captureId
                ))) {
                    $this->orderRepository->save($order);
                }
            }

            return;
        }

        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getId() !== $invoiceId) {
                continue;
            }
            if ($captureStatus === 'approved' && (int) $invoice->getState() !== Invoice::STATE_PAID) {
                $invoice->setState(Invoice::STATE_PAID);
                $invoice->save();
            } elseif ($captureStatus === 'rejected') {
                $this->logger->critical('Briqpay reports the capture of an invoice as rejected.', [
                    'orderId' => $order->getIncrementId(),
                    'invoiceId' => $invoiceId,
                    'captureId' => $captureId,
                ]);
                if ($this->noteOnce($order, (string) __(
                    'Briqpay reports capture %1 for invoice %2 as rejected: the amount was not captured.',
                    $captureId,
                    $invoice->getIncrementId()
                ))) {
                    $this->orderRepository->save($order);
                }
            }

            return;
        }

        throw new \Exception('Invoice not found for the given capture.');
    }

    private function orderByIncrementId(string $incrementId): ?Order
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId)->setPageSize(1)->create();
        $result = $this->orderRepository->getList($criteria);
        $orders = $result ? $result->getItems() : [];
        $order = reset($orders);

        return $order instanceof Order && (string) $order->getIncrementId() === $incrementId ? $order : null;
    }
}
