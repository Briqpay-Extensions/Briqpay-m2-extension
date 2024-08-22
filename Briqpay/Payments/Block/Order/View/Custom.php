<?php
namespace Briqpay\Payments\Block\Order\View;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

class Custom extends Template
{
    protected $_registry;

    /**
     * Constructor
     *
     * @param Context $context
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Context $context,
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
     * Check if any Briqpay data exists for the order
     *
     * This method checks if any of the relevant Briqpay data fields are present in the order.
     *
     * @return bool
     */
    public function hasBriqpayData()
    {
        $order = $this->getOrder();
        return $order && (
            $order->getData('briqpay_psp_display_name') ||
            $order->getData('briqpay_session_id') ||
            $order->getData('briqpay_psp_reservationId') ||
            $order->getData('briqpay_backoffice_url') ||
            $order->getData('briqpay_session_status')
        );
    }

    /**
     * Check if PSP display name exists for the order
     *
     * @return bool
     */
    public function hasPspDisplayName()
    {
        $order = $this->getOrder();
        return $order && $order->getData('briqpay_psp_display_name');
    }

    /**
     * Retrieve PSP display name for the order
     *
     * @return string|null
     */
    public function getPspDisplayName()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_psp_display_name') : null;
    }

    /**
     * Retrieve Briqpay session ID for the order
     *
     * @return string|null
     */
    public function getBriqpaySessionId()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_session_id') : null;
    }

    /**
     * Check if Briqpay session ID exists for the order
     *
     * @return bool
     */
    public function hasBriqpaySessionId()
    {
        $order = $this->getOrder();
        return $order && $order->getData('briqpay_session_id');
    }

    /**
     * Check if Briqpay PSP reservation ID exists for the order
     *
     * @return bool
     */
    public function hasBriqpayPspReservationId()
    {
        $order = $this->getOrder();
        return $order && $order->getData('briqpay_psp_reservationId');
    }

    /**
     * Retrieve Briqpay PSP reservation ID for the order
     *
     * @return string|null
     */
    public function getBriqpayPspReservationId()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_psp_reservationId') : null;
    }

    /**
     * Retrieve Briqpay backoffice URL for the order
     *
     * @return string|null
     */
    public function getBriqpayBackofficeUrl()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_backoffice_url') : null;
    }

    /**
     * Check if Briqpay backoffice URL exists for the order
     *
     * @return bool
     */
    public function hasBriqpayBackofficeUrl()
    {
        $order = $this->getOrder();
        return $order && $order->getData('briqpay_backoffice_url');
    }

    /**
     * Retrieve Briqpay session status for the order
     *
     * @return string|null
     */
    public function getBriqpaySessionStatus()
    {
        $order = $this->getOrder();
        return $order ? $order->getData('briqpay_session_status') : null;
    }

    /**
     * Check if Briqpay session status exists for the order
     *
     * @return bool
     */
    public function hasBriqpaySessionStatus()
    {
        $order = $this->getOrder();
        return $order && $order->getData('briqpay_session_status');
    }
}
