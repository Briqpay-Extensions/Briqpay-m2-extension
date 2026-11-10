<?php
declare(strict_types=1);

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\CustomTableFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Invoices in Magento a capture the payment method made by itself (Swish and other
 * auto-capture methods): once, offline, with the capture as its transaction. Used by the
 * capture webhook and by the order creation, so an order whose capture webhook arrived
 * before the order existed is invoiced as soon as it exists.
 */
class AutoCaptureInvoicer
{
    private Logger $logger;

    private OrderRepositoryInterface $orderRepository;

    private SearchCriteriaBuilder $searchCriteriaBuilder;

    private CustomTableFactory $customTableFactory;

    private InvoiceService $invoiceService;

    private TransactionFactory $transactionFactory;

    private LockManagerInterface $lockManager;

    public function __construct(
        Logger $logger,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CustomTableFactory $customTableFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        LockManagerInterface $lockManager
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->customTableFactory = $customTableFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->lockManager = $lockManager;
    }

    /**
     * Invoices every approved auto-capture of the session that has no invoice in Magento
     * yet. Nothing is thrown: the capture webhook, when it is retried, invoices too.
     *
     * @param Order $order
     * @param array<string, mixed> $session
     * @return void
     */
    public function invoiceExisting(Order $order, array $session): void
    {
        $transaction = $session['data']['transactions'][0] ?? [];
        if (empty($transaction['autoCaptureEnabled'])) {
            return;
        }
        foreach ($session['data']['captures'] ?? [] as $capture) {
            $captureId = (string) ($capture['captureId'] ?? '');
            if ($captureId === '' || ($capture['status'] ?? null) !== 'approved') {
                continue;
            }
            try {
                $this->invoiceOnce($order, $captureId, $session);
            } catch (\Exception $e) {
                $this->logger->warning('An existing auto-capture could not be invoiced with the order.', [
                    'orderId' => $order->getIncrementId(),
                    'captureId' => $captureId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Invoices one capture, once: a webhook may be delivered more than once, possibly at
     * the same time, and the order creation and the webhook can both try; the order is read again
     * under the lock, as the first invoice changes it.
     *
     * @param Order $order
     * @param string $captureId
     * @param array<string, mixed> $session
     * @return void
     */
    public function invoiceOnce(Order $order, string $captureId, array $session): void
    {
        // The capture's own lock only: order creation calls this while it holds the
        // session's lock, which not every lock backend lets a request take twice. The
        // capture webhook takes the session's lock itself before calling this.
        $name = 'briqpay_capture_' . $captureId;
        if (!$this->lockManager->lock($name, 30)) {
            throw new \Exception('The capture is being invoiced by another request.');
        }
        try {
            $collection = $this->customTableFactory->create()->getCollection();
            $collection->addFieldToFilter('capture_id', $captureId);
            if ((int) $collection->getFirstItem()->getInvoiceId() !== 0) {
                return;
            }
            $fresh = $this->orderByIncrementId((string) $order->getIncrementId());
            $this->invoice($fresh ?? $order, $captureId, $session);
        } finally {
            $this->lockManager->unlock($name);
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return void
     */
    private function invoice(Order $order, string $captureId, array $session): void
    {
        $captured = 0;
        foreach ($session['data']['captures'] ?? [] as $capture) {
            if (($capture['captureId'] ?? null) === $captureId) {
                $captured = (int) ($capture['amountIncVat'] ?? 0);
            }
        }
        $grandTotal = \Briqpay\Payments\Model\Utility\Money\MinorUnits::fromFloat((float) $order->getGrandTotal());
        if (!$order->canInvoice() || (float) $order->getTotalInvoiced() > 0 || $captured !== $grandTotal) {
            $this->logger->warning('Auto-capture not invoiced in Magento: it is not one capture of the whole order.', [
                'orderId' => $order->getIncrementId(),
                'captureId' => $captureId,
                'captured' => $captured,
                'grandTotal' => $grandTotal,
            ]);
            $payment = $order->getPayment();
            $note = (string) __(
                'Briqpay captured %1 (capture %2) by itself, but no invoice could be created in Magento: the '
                . 'order is on hold, partly invoiced, or the capture is not the whole order. Create the invoice '
                . 'offline once it can be.',
                number_format($captured / 100, 2, '.', '') . ' ' . $order->getOrderCurrencyCode(),
                $captureId
            );
            if ($payment === null || $payment->getAdditionalInformation('briqpay_auto_capture_note') !== $captureId) {
                if ($payment !== null) {
                    $payment->setAdditionalInformation('briqpay_auto_capture_note', $captureId);
                }
                $order->addCommentToStatusHistory($note);
                $this->orderRepository->save($order);
            }

            return;
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->setTransactionId($captureId);
        $invoice->register();
        $invoice->getOrder()->setIsInProcess(true);
        // An offline invoice records no capture transaction, and Magento finds a refund's
        // parent and transaction id ("<capture>-refund") through that transaction. Without
        // it a credit memo for this invoice keeps no transaction id.
        $payment = $order->getPayment();
        if ($payment instanceof \Magento\Sales\Model\Order\Payment) {
            $payment->setTransactionId($captureId);
            $payment->setIsTransactionClosed(false);
            $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE, $invoice, true);
        }
        $order->addCommentToStatusHistory((string) __(
            'Invoice created for Briqpay capture %1, which the payment method captured automatically.',
            $captureId
        ));
        $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder())->save();
        $this->logger->info('Invoiced an auto-capture.', [
            'orderId' => $order->getIncrementId(),
            'captureId' => $captureId,
        ]);
    }

    private function orderByIncrementId(string $incrementId): ?Order
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId)->setPageSize(1)->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($orders);

        return $order instanceof Order ? $order : null;
    }
}
