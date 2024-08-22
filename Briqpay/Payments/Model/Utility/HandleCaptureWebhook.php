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

    public function __construct(
        Logger $logger,
        OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $quoteRepository,
        ReadSession $readSession,
        CreateOrder $createOrder,
        AsyncCreateOrder $asyncCreateOrder,
        OrderManagementInterface $orderManagement,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CustomTableFactory $customTableFactory
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
    }

    public function processCaptureStatusWebhook(array $data): array
    {
        try {
            $quoteId = $data['quoteId'];
            $quote = $this->quoteRepository->get($quoteId);

           

            $event = $data['event'] ?? null;
            $autoCapture = $data['autoCaptured'] ?? null;
            $isPreExistingCapture = $data['isPreExistingCapture'] ?? null;
            $sessionId = $data['sessionId'] ?? null;
            $hookCaptureId = $data["captureId"] ?? null;
            // Get session data
            $session = $this->readSession->getSession($sessionId);

            if (!isset($session['status']) || $session['status'] !== 'completed') {
                $this->logger->warning('Session status is not completed.');
                return ['status' => true];
            }

            // Ensure quote session matches
            $quoteSession = $quote->getData('briqpay_session_id');
            if ($quoteSession !== $sessionId) {
                $this->logger->debug('Quote session ID does not match session ID. Quote session ID: ' . $quoteSession. 'Session ID: ' . $sessionId);
                return ['status' => true];
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
                    return ['status' => true,'message'=>"Did not find invoice in Magento, likely due to autocapture"];
                } else {
                    return ['status' => false, 'message' =>"Recieved hook with captureID that does not match session"];
                }
            }

            $captureStatus = $foundCorrectCapture["status"];

            if ($event === 'capture_status') {
                switch ($captureStatus) {
                    case 'pending':
                    case 'approved':
                    case 'rejected':
                        $this->handleCaptureStatus($quote, $captureStatus, $hookCaptureId);
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

    protected function handleCaptureStatus($quote, $captureStatus, $captureId)
    {

        try {
            // Load the quote by ID
            /** @var CartRepositoryInterface $quoteRepository */
           
            if (!$quote->getId()) {
                throw new \Exception('Quote not found');
            }
        
            // Get the order ID from the quote
            $orderId = $quote->getReservedOrderId();
            if (!$orderId) {
                throw new \Exception('Order ID not found for the given quote');
            }
          
            // Load the order by ID
            /** @var OrderRepositoryInterface $orderRepository */
           
            $order = $this->orderRepository->get($orderId);
            if (!$order->getEntityId()) {
                throw new \Exception('Order not found');
            }

            $this->logger->debug('handle capture status for orderid  ' . $orderId . ' and captureID : ' . $captureId.' with capture status '.$captureStatus);
            // use the briqpay table to fetch the correct invoice based on the orderID
            $collection = $this->customTableFactory->create()->getCollection();
            $collection->addFieldToFilter('capture_id', $captureId);
            $item = $collection->getFirstItem();
            $invoiceId =  $item->getInvoiceId();
            if (!$invoiceId) {
                $this->logger->error('No Invoice found for relevant captureID   ' . $captureId);
                throw new \Exception('InvoiceID not found for the given transaction ID');
            }
            
            // Find the invoice related to the capture
            $invoices = $order->getInvoiceCollection();
            $invoiceFound = false;
    //    var_dump($invoices);
            foreach ($invoices as $invoice) {
                if ($invoice->getId() === $invoiceId) {
                    $invoiceFound = true;
        
                    // Check if the invoice is already captured
                  
                    $invoice->setState(Invoice::STATE_PAID); // Set the state to paid
                    $invoice->save();
        
                    break;
                }
            }
        
            if (!$invoiceFound) {
                throw new \Exception('Invoice not found for the given transaction ID');
            }
        
            echo "Invoice and order status updated successfully.";
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }
}
