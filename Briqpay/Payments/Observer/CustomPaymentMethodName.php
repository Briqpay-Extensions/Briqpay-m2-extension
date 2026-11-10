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
        $transport = $observer->getEvent()->getTransport();
        $order = $transport->getOrder();
        if (!$order) {
            $this->logger->warning('Order object is not available');

            return;
        }
        $payment = $order->getPayment();
        if (!$payment) {
            $this->logger->warning('Payment object is not available');

            return;
        }
        $method = $payment->getMethodInstance();
        if ($method->getCode() !== 'briqpay') {
            return;
        }

        // The payment method the customer chose inside Briqpay (Invoice, Swish, ...):
        // on the order since 2.0, on the quote for orders placed before, else the
        // method's own title. The e-mail is sent whatever is missing.
        $displayName = (string) $order->getData('briqpay_psp_display_name');
        if ($displayName === '') {
            $displayName = $this->quoteDisplayName((int) $order->getQuoteId());
        }
        if ($displayName === '') {
            $displayName = (string) $method->getTitle();
        }
        if ($displayName === '') {
            return;
        }
        $transport->setData('payment_html', $displayName);
        $transport->setData('payment', $displayName);
    }

    private function quoteDisplayName(int $quoteId): string
    {
        if ($quoteId === 0) {
            return '';
        }
        try {
            return (string) $this->quoteRepository->get($quoteId)->getData('briqpay_psp_display_name');
        } catch (\Exception $e) {
            // Magento deletes converted quotes after a while.
            return '';
        }
    }
}
