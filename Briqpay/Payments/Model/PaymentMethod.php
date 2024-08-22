<?php
namespace Briqpay\Payments\Model;

use Magento\Payment\Model\InfoInterface;
use Briqpay\Payments\Model\OrderManagement\CaptureOrder;
use Briqpay\Payments\Model\OrderManagement\RefundOrder;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;
use Briqpay\Payments\Model\OrderManagement\CancelOrder as BriqpayCancelOrder;

/**
 * MD Custom Payment Method Model
 */
class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    /**
     * Payment Method code
     *
     * @var string
     */
    protected $_code = 'briqpay';

    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial= true;
    protected $_canVoid = true;
    protected $_canCancel = true;
    protected $refundOrder;
    protected $logger;
    protected $captureOrder;
    protected $customTableFactory;
    protected $_request;
    protected $briqpayCancelOrder;
    protected $creditmemoFactory;
    protected $invoiceRepository;
    protected $orderRepository;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\App\RequestInterface $request,
        CaptureOrder $captureOrder,
        CustomTableFactory $customTableFactory,
        RefundOrder $refundOrder,
        BriqpayCancelOrder $briqpayCancelOrder,
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );
        $this->logger = $logger;
        $this->_request = $request;
        $this->captureOrder = $captureOrder;
        $this->customTableFactory = $customTableFactory;
        $this->refundOrder = $refundOrder;
        $this->briqpayCancelOrder = $briqpayCancelOrder;
    }

    public function capture(InfoInterface $payment, $amount)
    {
        // Implement your capture logic here
        // Example:
        $this->_logger->info('Capture method called.' . $amount);
        // Get the order associated with the payment
        $order = $payment->getOrder();
        $this->_logger->info('Order ID: ' . $order->getIncrementId());
        $captureArray = $this->_request->getParams();

        $captureCart = array();
        foreach ($captureArray["invoice"]["items"] as $itemId => $quantity) {
            foreach ($order->getAllVisibleItems() as $item) {
                if ($item->getId() == $itemId) {
                    $item->setQuantity($quantity);
                    $captureCart[] = $item;
                }
            }
        }
           
        // Get payment method
        $paymentMethod = $payment->getMethod();

        // Get last transaction ID
        $lastTransactionId = $payment->getLastTransId();

        // Get additional information
        $additionalInfo = $payment->getAdditionalInformation();

        // Get payment amounts
        $amountOrdered = $payment->getAmountOrdered();
        $amountPaid = $payment->getAmountPaid();
    

        try {
            $order = $payment->getOrder();
            

            if ($order->getPayment()->getMethod() == 'briqpay') {
                $captureId=   $this->captureOrder->capture($order, $captureCart, $amount)["captureId"];
            }
            $payment->setTransactionId($captureId);
          //  $payment->setAdditionalInformation()
            $payment->setMethod('briqpay');
            $payment->save();
            return $this;
        } catch (LocalizedException $e) {
            $this->_logger->error('LocalizedException in CaptureInvoice observer: ' . $e->getMessage());
            throw $e; // Rethrow the exception to prevent invoice creation
        } catch (\Exception $e) {
            $this->_logger->error('General Exception in CaptureInvoice observer: ' . $e->getMessage());
            throw new LocalizedException(__('Error capturing invoice.'));
        }
    }
    /**
     * Void the payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        // Your custom logic to handle the cancellation
        $order = $payment->getOrder();
        
        // If there's an API call or any other logic to handle when cancelling the order, do it here
        // Example:
        // $apiResponse = $this->yourApiCancelOrder($order);
        // if (!$apiResponse->isSuccess()) {
        //     throw new \Magento\Framework\Exception\LocalizedException(__('Unable to cancel the order.'));
        // }
        try {
            $this->logger->info('Void call ', ['order_id' => $order->getId()]);

            if ($order->getPayment()->getMethod() == 'briqpay') {
                $this->_logger->info('Briqpay payment method detected and order is being voided.');
                $this->briqpayCancelOrder->cancel($order);
                 // Update the order status and state
                $order->setState(Order::STATE_CANCELED);
                $order->setStatus(Order::STATE_CANCELED);
                $order-save();
                return $this;
            }
        } catch (LocalizedException $e) {
            $this->_logger->error('Issue with void: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->_logger->error('General Exception in Void order : ' . $e->getMessage());
            throw new LocalizedException(__('Error voiding order.'));
        }
    }
    /**
     * Cancel the order
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        // Your custom logic to handle the cancellation
        $order = $payment->getOrder();
        
        // If there's an API call or any other logic to handle when cancelling the order, do it here
        // Example:
        // $apiResponse = $this->yourApiCancelOrder($order);
        // if (!$apiResponse->isSuccess()) {
        //     throw new \Magento\Framework\Exception\LocalizedException(__('Unable to cancel the order.'));
        // }
        try {
            $this->_logger->info('CancelCall done', ['order_id' => $order->getId()]);

            if ($order->getPayment()->getMethod() == 'briqpay') {
                $this->_logger->info('Briqpay payment method detected and order is being canceled.');
                $this->briqpayCancelOrder->cancel($order);
                 // Update the order status and state
                $order->setState(Order::STATE_CANCELED);
                $order->setStatus(Order::STATE_CANCELED);
                $order-save();
                return $this;
            }
        } catch (LocalizedException $e) {
            $this->_logger->error('LocalizedException in CancelOrder observer: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->_logger->error('General Exception in CancelOrder observer: ' . $e->getMessage());
            throw new LocalizedException(__('Error canceling order.'));
        }
    }

    public function refund(InfoInterface $payment, $amount)
    {
        $this->_logger->info('Refund Invoice called.');
        $captureArray = $this->_request->getParams();

        try {
            $creditMemo = $payment->getCreditmemo();
            
           
            $order = $payment->getOrder();
            

            // Check if the payment method is 'briqpay'
            if ($order->getPayment()->getMethod() == 'briqpay') {
                // Create a new credit memo
               
                $this->refundOrder->refund($creditMemo);
              
              //  $this->invoiceRepository->save($invoice);
            }
           
          //  $payment->setAdditionalInformation()
            $payment->setMethod('briqpay');
            $payment->save();

            return $this;
        } catch (\Exception $e) {
            $this->_logger->error('Error during refund: ' . $e->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(__('Error during refund: %1', $e->getMessage()));
        }
    }
}
