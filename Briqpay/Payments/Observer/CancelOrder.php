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

            // Cancelling a partly invoiced order leaves it processing or complete, not
            // canceled; its uninvoiced rest must still be released at Briqpay.
            $cancelled = $order->getState() == Order::STATE_CANCELED || (float) $order->getTotalInvoiced() > 0;
            if ($order->getPayment()->getMethod() == 'briqpay' && $cancelled) {
                $this->logger->info('Briqpay payment method detected and order is being cancelled.');
                $cancelReturn = $this->briqpayCancelOrder->cancel($order);

                if (isset($cancelReturn['error'])
                    && $cancelReturn['error'] === BriqpayCancelOrder::RELEASE_NOT_SUPPORTED
                ) {
                    $warning = __(
                        'The uninvoiced items are cancelled in Magento, but the payment method cannot release the '
                        . 'uncaptured remainder at Briqpay. Release it with the payment provider, or let the '
                        . 'authorisation expire.'
                    );
                    $this->messageManager->addWarningMessage($warning);
                    // Magento saves the order after this event, so the note stays with it.
                    $order->addCommentToStatusHistory((string) $warning);
                }
                if (isset($cancelReturn['error']) && $cancelReturn['error'] === \Briqpay\Payments\Rest\ApiClient::CANCEL_NOT_SUPPORTED) {
                    $this->logger->warning('Order cancelled in Magento, but not at Briqpay: the payment method does not support it.');
                    $this->messageManager->addWarningMessage(__(
                        'The payment method cannot cancel the payment at Briqpay. Cancel it with the payment provider.'
                    ));
                }
            }
        } catch (LocalizedException $e) {
            $this->logger->error('LocalizedException in CancelOrder observer: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('General Exception in CancelOrder observer: ' . $e->getMessage());
            throw new LocalizedException(__('Error cancelling order.'));
        }
    }
}
