<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\HostedPage\CreatePaymentLink;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Rest\ApiRequestException;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Cancels what Briqpay still holds of an order Magento cancels.
 *
 * Nothing captured: the authorisation is cancelled. Partly captured (Magento
 * cancels the uninvoiced rest): the uncaptured remainder is released, because
 * Briqpay refuses to cancel an order with captures. Fully captured: nothing is left
 * to release.
 */
class CancelOrder
{
    /**
     * Returned in 'error' when the payment method cannot release an uncaptured remainder.
     */
    public const RELEASE_NOT_SUPPORTED = 'release_not_supported';

    private $setupConfig;
    private $apiClient;
    private $logger;
    private $quoteFactory;
    private $orderRepository;
    /**
     * @var ReadSession
     */
    private $readSession;

    /**
     * @var \Briqpay\Payments\Model\Utility\ScopeHelper|null
     */
    private $scopeHelper;

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger,
        QuoteFactory $quoteFactory,
        OrderRepositoryInterface $orderRepository,
        ReadSession $readSession,
        ?\Briqpay\Payments\Model\Utility\ScopeHelper $scopeHelper = null
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->orderRepository = $orderRepository;
        $this->readSession = $readSession;
        $this->scopeHelper = $scopeHelper
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Briqpay\Payments\Model\Utility\ScopeHelper::class);
    }

    public function cancel(Order $order)
    {
        // Credentials and test mode are the order's store's, not the admin's.
        $cancel = function () use ($order) {
            return $this->cancelInStore($order);
        };

        return $this->scopeHelper->inStore($order->getStoreId(), $cancel);
    }

    /**
     * See cancel().
     *
     * @param Order $order
     * @return mixed
     * @throws LocalizedException
     */
    private function cancelInStore(Order $order)
    {
        $this->logger->info('CancelOrder::cancel method called.');

        // The order holds the session id; Magento deletes converted quotes after a while.
        $briqpaySessionId = $order->getData('briqpay_session_id');
        if (!$briqpaySessionId) {
            // Orders from earlier module versions keep the session id on the quote only.
            $quote = $this->quoteFactory->create()->load($order->getQuoteId());
            $briqpaySessionId = $quote->getData('briqpay_session_id');
        }

        if (!$briqpaySessionId && CreatePaymentLink::unpaid($order) && CreatePaymentLink::placedInAdmin($order)) {
            // An admin order that never got a payment link: nothing of it at Briqpay.
            $this->logger->info('The order was never paid through Briqpay; nothing to cancel there.');

            return ['orderStatus' => 'not_paid'];
        }
        if (!$briqpaySessionId) {
            $this->logger->error('Briqpay session ID is not available on the order or its quote.');
            throw new LocalizedException(__('This order has no Briqpay session, so it cannot be cancelled at Briqpay. Cancel the payment with the payment provider, then cancel the order.'));
        }

        $this->logger->info('Briqpay session ID retrieved.', [
            'briqpaySessionId' => $briqpaySessionId
        ]);

        $ledger = $this->readLedger($briqpaySessionId);
        if ($ledger !== null && $ledger->captures() !== []) {
            return $this->releaseRemaining($order, $briqpaySessionId, (string) $ledger->orderStatus());
        }
        // Briqpay's own rejection or cancellation (reported by webhook) is what cancels
        // this order; there is nothing left to cancel at Briqpay.
        if ($ledger !== null && in_array($ledger->orderStatus(), ['order_rejected', 'order_cancelled'], true)) {
            $this->logger->info('Briqpay already reports the payment as ' . $ledger->orderStatus() . '; nothing to cancel.');

            return ['orderStatus' => $ledger->orderStatus()];
        }
        $linkOrder = ($ledger === null || !$ledger->completed())
            && CreatePaymentLink::unpaid($order) && CreatePaymentLink::placedInAdmin($order);
        if ($linkOrder && $ledger === null && !$this->ledgerRefused) {
            // Briqpay did not answer: the link may have been paid a moment ago, and a
            // cancel in Magento alone would leave that payment's authorisation open.
            throw new LocalizedException(__(
                'Briqpay could not be reached to confirm that the payment link was never paid. '
                . 'Nothing was cancelled; try again in a moment.'
            ));
        }
        if ($linkOrder) {
            // A payment link that was never paid: there is no authorisation to cancel.
            $this->logger->info('The payment link was never paid; nothing to cancel at Briqpay.');
            $order->addCommentToStatusHistory((string) __(
                'The Briqpay payment link was never paid, so nothing was cancelled at Briqpay. The link stays '
                . 'open there; a payment made on it is reported on this order.'
            ));

            return ['orderStatus' => 'not_paid'];
        }

        $body = ['data' => []];

        // Make API request to Briqpay
        $uri = '/v3/session/' . $briqpaySessionId . '/order/cancel';

        try {
            $response = $this->apiClient->request('POST', $uri, $body);
            $this->logger->debug('Briqpay cancel request sent.', [
                'response' => $response
            ]);

            // Update order status to "canceled"
            $order->setState(Order::STATE_CANCELED)
                ->setStatus(Order::STATE_CANCELED);
            $this->orderRepository->save($order);
            $this->logger->info('Order status updated to "canceled".');

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error cancelling order: ' . $e->getMessage(), [
                'exception' => $e,
                'orderId' => $order->getIncrementId(),
                'body' => $body
            ]);
            throw new LocalizedException(__('The order could not be cancelled at Briqpay: %1', CaptureOrder::readableError($e)));
        }
    }

    /**
     * Magento decides the state of a partly invoiced order it cancels, so it is left
     * alone here.
     *
     * @return array<string, mixed>
     */
    private function releaseRemaining(Order $order, string $briqpaySessionId, string $orderStatus): array
    {
        if ($orderStatus !== 'captured_partial') {
            $this->logger->info('Nothing left to release at Briqpay.', [
                'orderId' => $order->getIncrementId(),
                'orderStatus' => $orderStatus,
            ]);

            return [];
        }

        try {
            $response = $this->apiClient->request(
                'POST',
                '/v3/session/' . $briqpaySessionId . '/order/release-remaining'
            );
            $this->logger->info('Released the uncaptured remainder at Briqpay.', [
                'orderId' => $order->getIncrementId(),
            ]);

            return $response ?? [];
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'NOT_SUPPORTED') !== false) {
                // The payment method cannot release part of a payment. Blocking the cancel
                // would leave the merchant no way to close the order, so it goes ahead and
                // the merchant is told to release the rest with the payment provider.
                $this->logger->warning('The payment method does not support releasing the remaining amount.', [
                    'orderId' => $order->getIncrementId(),
                ]);

                return ['error' => self::RELEASE_NOT_SUPPORTED];
            }
            $this->logger->error('Error releasing the remaining amount: ' . $e->getMessage(), [
                'orderId' => $order->getIncrementId(),
            ]);
            throw new LocalizedException(__(
                'The remaining amount could not be released at Briqpay: %1',
                CaptureOrder::readableError($e)
            ));
        }
    }

    /**
     * Whether Briqpay does not know the session (unknown or expired), as opposed to any
     * other failure; set by readLedger().
     *
     * @var bool
     */
    private bool $ledgerRefused = false;

    /**
     * An unreadable session falls back to a plain cancel.
     */
    private function readLedger(string $briqpaySessionId): ?SessionLedger
    {
        $this->ledgerRefused = false;
        try {
            return new SessionLedger((array) $this->readSession->getSession($briqpaySessionId));
        } catch (\Exception $e) {
            // Only a session Briqpay does not know (any longer); not credentials, rate
            // limits or a timeout, which say nothing about the link.
            $this->ledgerRefused = in_array(ApiRequestException::statusIn($e), [404, 410], true);
            $this->logger->error('Could not read the session before cancelling: ' . $e->getMessage());

            return null;
        }
    }
}
