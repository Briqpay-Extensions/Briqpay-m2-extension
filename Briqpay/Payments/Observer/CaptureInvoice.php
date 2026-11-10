<?php

namespace Briqpay\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\OrderManagement\CaptureOrder;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;

class CaptureInvoice implements ObserverInterface
{
    protected $logger;
    protected $captureOrder;
    protected $customTableFactory;

    public function __construct(
        Logger $logger,
        CaptureOrder $captureOrder,
        CustomTableFactory $customTableFactory
    ) {
        $this->logger = $logger;
        $this->captureOrder = $captureOrder;
        $this->customTableFactory = $customTableFactory;
    }

    public function execute(Observer $observer)
    {
        try {
            $invoice = $observer->getEvent()->getInvoice();
            $order = $invoice->getOrder();

            if ($order->getPayment()->getMethod() == 'briqpay' && !empty($invoice->getTransactionId())
                && !$this->isRecorded((int) $invoice->getId())
            ) {
                foreach ($invoice->getAllItems() as $item) {
                    $customTable = $this->customTableFactory->create();
                    $quantity = $item->getQty();
                    $customTable->setData([
                        'invoice_id' => $invoice->getId(),
                        'order_id' => $order->getId(),
                        'item_id' => $item->getOrderItemId(),
                        'capture_id' => $invoice->getTransactionId(),
                        'quantity' => $quantity // Set the quantity here
                    ]);
                    $customTable->save();
                }
            }
        } catch (LocalizedException $e) {
            $this->logger->error('LocalizedException in CaptureInvoice observer: ' . $e->getMessage());
            throw $e; // Rethrow the exception to prevent invoice creation
        } catch (\Exception $e) {
            $this->logger->error('General Exception in CaptureInvoice observer: ' . $e->getMessage());
            throw new LocalizedException(__('Error capturing invoice.'));
        }
    }

    /**
     * Whether the invoice's capture is already recorded: the invoice is saved again
     * later, for example when Briqpay confirms the capture, and must not be recorded twice.
     */
    private function isRecorded(int $invoiceId): bool
    {
        $collection = $this->customTableFactory->create()->getCollection();
        $collection->addFieldToFilter('invoice_id', $invoiceId);

        return $collection->getSize() > 0;
    }
}
