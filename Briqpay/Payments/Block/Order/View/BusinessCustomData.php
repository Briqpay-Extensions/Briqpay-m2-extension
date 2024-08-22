<?php
namespace Briqpay\Payments\Block\Order\View;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

class BusinessCustomData extends Template
{
    protected $_registry;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->_registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Retrieve current order model instance
     *
     * @return Order|null
     */
    public function getOrder()
    {
        return $this->_registry->registry('current_order');
    }

    /**
     * Check if any of the business custom data fields exist for the order
     *
     * @return bool
     */
    public function hasBusinessCustomData()
    {
        $order = $this->getOrder();
        return $order && (
            $order->getData('briqpay_cin') ||
            $order->getData('briqpay_company_name') ||
            $order->getData('briqpay_company_vatNo')
        );
    }

    /**
     * Retrieve Company CIN for the order
     *
     * @return string|null
     */
    public function getCompanyCin()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_cin') : null;
    }

    /**
     * Retrieve Company Name for the order
     *
     * @return string|null
     */
    public function getCompanyName()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_company_name') : null;
    }

    /**
     * Retrieve Company VAT Number for the order
     *
     * @return string|null
     */
    public function getCompanyVatNo()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_company_vatNo') : null;
    }
}
