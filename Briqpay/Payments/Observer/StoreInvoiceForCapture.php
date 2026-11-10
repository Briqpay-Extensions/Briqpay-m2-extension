<?php
/**
 * Hands the invoice being captured to the Briqpay payment method.
 *
 * Magento dispatches sales_order_payment_capture with the payment and the invoice
 * just before it calls the method's capture(), but passes the method only the
 * payment and an amount. Taking the invoice from here works however the invoice is
 * created: admin, REST, the CLI or another module.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class StoreInvoiceForCapture implements ObserverInterface
{
    public const PAYMENT_KEY = 'briqpay_capture_invoice';

    /**
     * Puts the invoice on a Briqpay payment, where the payment method's capture() reads it.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $payment = $observer->getEvent()->getData('payment');
        $invoice = $observer->getEvent()->getData('invoice');
        if ($payment && $invoice && $payment->getMethod() === 'briqpay') {
            $payment->setData(self::PAYMENT_KEY, $invoice);
        }
    }
}
