<?php
declare(strict_types=1);

namespace Briqpay\Payments\Observer;

use Briqpay\Payments\Model\Utility\OrderCreationGuard;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Refuses to place a Briqpay order that has no payment behind it.
 *
 * Orders with Briqpay are placed only by the Briqpay checkout, once Briqpay holds the
 * payment, or in the admin, where a payment link follows; other place-order calls are
 * refused.
 */
class RefuseUnpaidPlacement implements ObserverInterface
{
    private OrderCreationGuard $guard;

    private State $appState;

    public function __construct(OrderCreationGuard $guard, State $appState)
    {
        $this->guard = $guard;
        $this->appState = $appState;
    }

    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        $payment = $order ? $order->getPayment() : null;
        if (!$payment || $payment->getMethod() !== 'briqpay' || $this->guard->isPlacing() || $this->inAdmin()) {
            return;
        }

        throw new LocalizedException(__(
            'A Briqpay order is placed by the Briqpay checkout once the payment is made.'
        ));
    }

    private function inAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (LocalizedException $e) {
            return false;
        }
    }
}
