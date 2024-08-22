<?php

namespace Briqpay\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Briqpay\Payments\Logger\Logger;
use Magento\Quote\Api\CartRepositoryInterface;

class CustomPaymentMethodName implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    protected $quoteRepository;
    /**
     * Constructor
     *
     * @param Logger $logger
     */
    public function __construct(Logger $logger, CartRepositoryInterface $quoteRepository)
    {
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * Execute observer method
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        // Log that the observer has been triggered
        $this->logger->info('CustomPaymentMethodName Observer triggered');

        $transport = $observer->getEvent()->getTransport();
        $order = $transport->getOrder();

        if ($order) {
            $this->logger->info('Order ID: ' . $order->getIncrementId());

            $payment = $order->getPayment();
            $quoteId = $order->getQuoteId();
            $quote = $this->quoteRepository->get($quoteId);
            if ($payment) {
                $paymentMethodCode = $payment->getMethodInstance()->getCode();

                if ($paymentMethodCode == 'briqpay') {
                    $realPaymentMethod = $quote->getData('briqpay_psp_display_name');

                    if ($realPaymentMethod) {
                        // Modify the payment method name in the transport variables
                        $transport->setData('payment_html', $realPaymentMethod);
                        $transport->setData('payment', $realPaymentMethod);
                    } else {
                        $this->logger->warning('Payment Method Display Name is empty');
                        $transport->setData('payment_html', 'foo');
                        $transport->setData('payment', 'foo');
                    }
                } else {
                    $this->logger->info('Payment Method Code does not match "briqpay"');
                }
            } else {
                $this->logger->warning('Payment object is not available');
            }
        } else {
            $this->logger->warning('Order object is not available');
        }
    }
}
