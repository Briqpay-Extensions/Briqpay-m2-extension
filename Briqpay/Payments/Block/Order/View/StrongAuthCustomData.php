<?php
namespace Briqpay\Payments\Block\Order\View;

use DateTime;
use DateTimeZone;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

class StrongAuthCustomData extends Template
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

    public function getOrder()
    {
        return $this->_registry->registry('current_order');
    }

    public function hasStrongAuthData()
    {
        $order = $this->getOrder();
        return $order && $order->getData('briqpay_strong_auth');
    }

    protected function getDecodedStrongAuthData()
    {
        $order = $this->getOrder();
        $strongAuthData = $order ? $order->getData('briqpay_strong_auth') : null;

        if ($strongAuthData) {
            return json_decode(base64_decode($strongAuthData), true);
        }
        return null;
    }

    public function getStrongAuthProvider()
    {
        $decodedData = $this->getDecodedStrongAuthData();
        return $decodedData['provider'] ?? null;
    }

    public function getSignedAt()
    {
        $decodedData = $this->getDecodedStrongAuthData();
        if (!isset($decodedData['output']['signedAt'])) {
            return null;
        }
        $signedAt = $decodedData['output']['signedAt'];
        $utcDateTime = new DateTime($signedAt, new DateTimeZone('UTC'));
        $targetTimeZone = new DateTimeZone('Europe/Stockholm');
        $utcDateTime->setTimezone($targetTimeZone);
        $readableDate = $utcDateTime->format('Y-m-d H:i:s');
        return $readableDate;
    }

    public function getName()
    {
        $decodedData = $this->getDecodedStrongAuthData();
        return $decodedData['output']['name'] ?? null;
    }

    public function getSurname()
    {
        $decodedData = $this->getDecodedStrongAuthData();
        return $decodedData['output']['surname'] ?? null;
    }

    public function getGivenName()
    {
        $decodedData = $this->getDecodedStrongAuthData();
        return $decodedData['output']['givenName'] ?? null;
    }

    public function getDateOfBirth()
    {
        $decodedData = $this->getDecodedStrongAuthData();
        return $decodedData['output']['dateOfBirth'] ?? null;
    }

    public function isCompanySignatory()
    {
        $decodedData = $this->getDecodedStrongAuthData();
        return $decodedData['output']['validateSigneeIsCompanySignatory'] ?? null;
    }
}
