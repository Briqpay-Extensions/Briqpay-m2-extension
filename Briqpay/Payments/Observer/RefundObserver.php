<?php

namespace Briqpay\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\OrderManagement\RefundOrder;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;

class RefundObserver implements ObserverInterface
{
    protected $logger;
    protected $refundOrder;
    protected $customTableFactory;

    public function __construct(
        Logger $logger,
        RefundOrder $refundOrder,
        CustomTableFactory $customTableFactory
    ) {
        $this->logger = $logger;
        $this->refundOrder = $refundOrder;
        $this->customTableFactory = $customTableFactory;
    }

    public function execute(Observer $observer)
    {
        try {
            $creditmemo = $observer->getEvent()->getCreditmemo();
            $order = $creditmemo->getOrder();

            if ($order->getPayment()->getMethod() == 'briqpay') {
                $this->refundOrder->refund($creditmemo);
            }
        } catch (LocalizedException $e) {
            $this->logger->error('LocalizedException in RefundObserver observer: ' . $e->getMessage());
            throw $e; // Rethrow the exception to prevent credit memo creation
        } catch (\Exception $e) {
            $this->logger->error('General Exception in RefundObserver observer: ' . $e->getMessage());
            throw new LocalizedException(__('Error processing refund.'));
        }
    }
}
