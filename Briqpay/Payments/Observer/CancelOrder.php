<?php

namespace Briqpay\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\OrderManagement\CancelOrder as BriqpayCancelOrder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Framework\Message\ManagerInterface;

class CancelOrder implements ObserverInterface
{
    protected $logger;
    protected $briqpayCancelOrder;
    protected $messageManager;

    public function __construct(
        Logger $logger,
        BriqpayCancelOrder $briqpayCancelOrder,
        ManagerInterface $messageManager
    ) {
        $this->logger = $logger;
        $this->briqpayCancelOrder = $briqpayCancelOrder;
        $this->messageManager = $messageManager;
    }

    public function execute(Observer $observer)
    {
        $this->logger->info('CancelOrder observer triggered.');

        try {
            $order = $observer->getEvent()->getOrder();
            $this->logger->info('Order retrieved in observer.', ['order_id' => $order->getId()]);

            if ($order->getPayment()->getMethod() == 'briqpay' && $order->getState() == Order::STATE_CANCELED) {
                $this->logger->info('Briqpay payment method detected and order is being canceled.');
                $cancelReturn = $this->briqpayCancelOrder->cancel($order);

                if (isset($cancelReturn['error']) && $cancelReturn['error'] == 'Order not canceled not at PSP') {
                    $this->logger->warning('Order canceled in Magento, but not at PSP. Warning: Order not canceled not at PSP.');
                    $this->messageManager->addWarningMessage(__('The payment method does not support cancel at Briqpay. Please go to the payment provider and cancel the order manually'));
                }
            }
        } catch (LocalizedException $e) {
            $this->logger->error('LocalizedException in CancelOrder observer: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('General Exception in CancelOrder observer: ' . $e->getMessage());
            throw new LocalizedException(__('Error canceling order.'));
        }
    }
}
