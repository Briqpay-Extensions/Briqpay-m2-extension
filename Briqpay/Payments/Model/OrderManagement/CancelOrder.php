<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

class CancelOrder
{
    private $setupConfig;
    private $apiClient;
    private $logger;
    private $quoteFactory;
    private $orderRepository;

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger,
        QuoteFactory $quoteFactory,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->orderRepository = $orderRepository;
    }

    public function cancel(Order $order)
    {
        $this->logger->info('CancelOrder::cancel method called.');

        // Load the quote using the quote ID from the order
        $quoteId = $order->getQuoteId();
        $quote = $this->quoteFactory->create()->load($quoteId);

        // Get the Briqpay session ID from the quote
        $briqpaySessionId = $quote->getData('briqpay_session_id');

        if (!$briqpaySessionId) {
            $this->logger->error('Briqpay session ID is not available in the quote.');
            throw new LocalizedException(__('Briqpay session ID is not available in the quote.'));
        }

        $this->logger->info('Briqpay session ID retrieved.', [
            'briqpaySessionId' => $briqpaySessionId
        ]);

        // Prepare request body (if needed)
        $body = [
            'data' => [
                // Add any necessary data for the cancel request
            ]
        ];

       

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
            $this->logger->error('Error canceling order: ' . $e->getMessage(), [
                'exception' => $e,
                'orderId' => $order->getIncrementId(),
                'body' => $body
            ]);
            throw new LocalizedException(__('Error canceling order: ' . $e->getMessage()));
        }
    }
}
