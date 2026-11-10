<?php
declare(strict_types=1);

namespace Briqpay\Payments\Observer;

use Briqpay\Payments\Model\HostedPage\CreatePaymentLink;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * An order placed in the admin with Briqpay has no payment until its link is paid:
 * an invoice made before that (offline, say) would mark it paid in Magento while the
 * authorisation that follows could never be captured through Magento.
 */
class RefuseInvoiceBeforePayment implements ObserverInterface
{
    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        $invoice = $observer->getEvent()->getData('invoice');
        if (!$order instanceof Order || !CreatePaymentLink::placedInAdmin($order) || !CreatePaymentLink::unpaid($order)) {
            return;
        }
        if ($invoice instanceof Order\Invoice && (string) $invoice->getTransactionId() !== '') {
            // The invoice of a capture Briqpay made itself (an auto-capture method's
            // webhook, which may arrive before the order's payment status): a payment.
            return;
        }

        throw new LocalizedException(__(
            'This order is waiting for its Briqpay payment link to be paid and cannot be invoiced before then.'
        ));
    }
}
