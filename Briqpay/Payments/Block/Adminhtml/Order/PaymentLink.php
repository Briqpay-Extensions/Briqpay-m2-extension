<?php
declare(strict_types=1);

namespace Briqpay\Payments\Block\Adminhtml\Order;

use Briqpay\Payments\Model\HostedPage\CreatePaymentLink;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

/**
 * The "Briqpay payment link" box on the admin order view: for a Briqpay order waiting
 * for its payment, the link if one exists and the form that creates or replaces it.
 */
class PaymentLink extends Template
{
    private Registry $registry;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param array<string, mixed> $data
     */
    public function __construct(Context $context, Registry $registry, array $data = [])
    {
        parent::__construct($context, $data);
        $this->registry = $registry;
    }

    public function getOrder(): ?Order
    {
        $order = $this->registry->registry('current_order');

        return $order instanceof Order ? $order : null;
    }

    public function isShown(): bool
    {
        $order = $this->getOrder();

        return $order !== null && CreatePaymentLink::accepts($order);
    }

    public function getLinkUrl(): string
    {
        return (string) $this->additionalInformation(CreatePaymentLink::LINK_URL);
    }

    public function getLinkCreatedAt(): string
    {
        return (string) $this->additionalInformation(CreatePaymentLink::LINK_CREATED_AT);
    }

    public function getLinkCustomerType(): string
    {
        return (string) $this->additionalInformation(CreatePaymentLink::LINK_CUSTOMER_TYPE);
    }

    /**
     * The customer type preselected: the existing link's, as a link for the other type
     * is a new session; otherwise business when the billing address names a company.
     */
    public function getDefaultCustomerType(): string
    {
        if ($this->getLinkCustomerType() !== '') {
            return $this->getLinkCustomerType();
        }
        $order = $this->getOrder();
        $company = $order && $order->getBillingAddress() ? trim((string) $order->getBillingAddress()->getCompany()) : '';

        return $company !== '' ? 'business' : 'consumer';
    }

    public function getCreateUrl(): string
    {
        $order = $this->getOrder();

        return $this->getUrl('briqpay/hostedPage/create', ['order_id' => $order ? (int) $order->getId() : 0]);
    }

    /**
     * @return mixed
     */
    private function additionalInformation(string $key)
    {
        $order = $this->getOrder();
        $payment = $order ? $order->getPayment() : null;

        return $payment ? $payment->getAdditionalInformation($key) : null;
    }
}
