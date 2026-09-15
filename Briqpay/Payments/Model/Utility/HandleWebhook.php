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

class HandleWebhook
{
    protected $logger;
    protected $orderRepository;
    protected $quoteRepository;
    protected $createOrder;
    protected $readSession;
    protected $asyncCreateOrder;
    protected $orderManagement;
    protected $searchCriteriaBuilder;

    public function __construct(
        Logger $logger,
        OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $quoteRepository,
        ReadSession $readSession,
        CreateOrder $createOrder,
        AsyncCreateOrder $asyncCreateOrder,
        OrderManagementInterface $orderManagement,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->quoteRepository = $quoteRepository;
        $this->readSession = $readSession;
        $this->createOrder = $createOrder;
        $this->asyncCreateOrder = $asyncCreateOrder;
        $this->orderManagement = $orderManagement;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    public function isQuoteConvertedToOrder($quote)
    {
       // $quote = $this->quoteRepository->get($quoteId);
        return !$quote->getIsActive();
    }
    public function processOrderStatusWebhook(array $data): array
    {
        try {
            $quoteId = $data['quoteId'];
            $quote = $this->quoteRepository->get($quoteId);

            $event = $data['event'] ?? null;
            $sessionId = $data['sessionId'] ?? null;

            if (is_null($quote->getData('briqpay_session_id'))) {
                $quote->setData('briqpay_session_id', $sessionId);
                $this->quoteRepository->save($quote);
            }

            // Get session data
            $session = $this->readSession->getSession($sessionId);

            if (!isset($session['status']) || $session['status'] !== 'completed') {
                // A real, no-op-intended bail (Briqpay hasn't finished processing this
                // session yet) is normal for order_pending. But for a later status like
                // order_approved_not_captured or captured_full/partial, this returning
                // 200 OK while silently doing nothing is exactly what looks like "the
                // webhook was received and handled but the order never updated, with no
                // error anywhere" - so it's logged at warning with full context, not info.
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
                // Same reasoning as above: silently skipping here on a mismatch is
                // indistinguishable from success unless logged loudly with the actual
                // values, which is what made this class of bug invisible before.
                $this->logger->warning('HandleWebhook: quote\'s stored briqpay_session_id does not match the webhook\'s sessionId - order status update skipped.', [
                    'quoteId' => $quoteId,
                    'quoteSessionId' => $quoteSession,
                    'webhookSessionId' => $sessionId,
                    'event' => $event,
                ]);
                return ['status' => true];
            }

            // Get transaction status
            $transactions = $session['data']['transactions'] ?? [];
            $briqpaySessionStatus = !empty($transactions) ? $transactions[0]['status'] : '';

            if ($event === 'order_status') {
                $orderStatus = $session['moduleStatus']['payment']['orderStatus'] ?? null;
                switch ($orderStatus) {
                    case 'order_approved_not_captured':
                    case 'captured_full':
                    case 'captured_partial':
                    case 'order_pending':
                    case 'order_rejected':
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
        $statusMappings = [
            'order_approved_not_captured' => [
                'state' => Order::STATE_PROCESSING,
                'status' => Order::STATE_PROCESSING,
            ],
            'order_pending' => [
                'state' => Order::STATE_NEW,
                'status' => 'pending',
            ],
            'order_rejected' => [
                'state' => Order::STATE_CANCELED,
                'status' => 'canceled',
            ],
            'captured_full' => [
                'state' => Order::STATE_PROCESSING,
                'status' => Order::STATE_PROCESSING,
            ],
            // Magento has no state distinct from "processing" for a partially captured
            // order, so this mirrors captured_full. Missing this case (silently falling
            // to the "Unknown order status" default, doing nothing) is what left orders
            // stuck on their pre-capture status after a partial capture - see the
            // remediation script for orders affected before this fix.
            'captured_partial' => [
                'state' => Order::STATE_PROCESSING,
                'status' => Order::STATE_PROCESSING,
            ],
        ];

        try {
            $reservedOrderId = $quote->getReservedOrderId();
            if ($reservedOrderId) {
                // Fetch order by increment ID
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $reservedOrderId, 'eq')
                    ->create();
                $orders = $this->orderRepository->getList($searchCriteria)->getItems();
                $order = reset($orders);

                if ($order && $order->getEntityId()) {
                    if ($order->getState() !== $statusMappings[$orderStatus]['state']) {
                        $order->setState($statusMappings[$orderStatus]['state']);
                        $order->setStatus($statusMappings[$orderStatus]['status']);
                        $order->setData('briqpay_session_status', $briqpaySessionStatus);
                        $this->orderRepository->save($order);
                        $this->logger->info('Order ID: ' . $order->getEntityId() . ' status updated to ' . $orderStatus . '.');
                    }
                } else {
                    $this->logger->debug('Order entity ID not found for Quote ID: ' . $quoteId);
                    $this->asyncCreateOrder->createOrderFromWebhook($quoteId, $session);
                }
            } else {
                $this->logger->debug('No reserved order ID found for Quote ID: ' . $quoteId);
                $this->asyncCreateOrder->createOrderFromWebhook($quoteId, $session);
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->logger->error('Order with reserved order ID ' . $reservedOrderId . ' not found.');
            throw new \Exception('Order with reserved order ID ' . $reservedOrderId . ' not found.');
        } catch (\Exception $e) {
            $this->logger->error('Error handling order status for Quote ID: ' . $quoteId . '. Error: ' . $e->getMessage());
            throw new \Exception('Error handling order status for Quote ID: ' . $quoteId . '. Error: ' . $e->getMessage());
        }
    }
}
