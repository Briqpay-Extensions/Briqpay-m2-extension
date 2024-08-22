<?php
namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\PaymentModule\UpdateSession;
use Briqpay\Payments\Model\Utility\CompareData;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\Session\SessionManagerInterface;

/**
 * Class ReinitialiseSession
 * @package Briqpay\Payments\Model\Utility
 */
class ReinitialiseSession
{
    protected $sessionManager;
    /**
     * @var ReadSession
     */
    protected $readSession;

    /**
     * @var UpdateSession
     */
    protected $updateSession;

    /**
     * @var AssignBillingAddress
     */
    protected $billingData;

    /**
     * @var AssignShippingAddress
     */
    protected $shippingData;

    /**
     * @var GenerateCart
     */
    protected $cart;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var CompareData
     */
    protected $compareData;

    
    /**
     * ReinitialiseSession constructor.
     * @param AssignBillingAddress $billingData
     * @param AssignShippingAddress $shippingData
     * @param GenerateCart $cart
     * @param ReadSession $readSession
     * @param UpdateSession $updateSession
     * @param CompareData $compareData
     * @param Logger $logger
     */
    public function __construct(
        AssignBillingAddress $billingData,
        AssignShippingAddress $shippingData,
        GenerateCart $cart,
        ReadSession $readSession,
        UpdateSession $updateSession,
        CompareData $compareData,
        Logger $logger,
        SessionManagerInterface $sessionManager
    ) {
        $this->billingData = $billingData;
        $this->shippingData = $shippingData;
        $this->cart = $cart;
        $this->readSession = $readSession;
        $this->updateSession = $updateSession;
        $this->compareData = $compareData;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Reinitialises session data based on changes in billing, shipping, or cart.
     * @param string $sessionId
     * @return array
     * @throws \Exception
     */
    public function reinitialiseSession(string $sessionId, $fallbackEmail = null): array
    {
        try {
            // Fetch current session data
            $session = $this->readSession->getSession($sessionId);
            $sessionData = $session['data'] ?? [];
            if ($session["status"] === "completed") {
                $this->sessionManager->unsetData('briqpay_session_id');
                throw new \Exception('Unable to restart an already completed session');
            }
            // Fetch current billing, shipping, and cart data
            $billingData = $this->billingData->getBillingData();
            $shippingData = $this->shippingData->getShippingData();
            $cart = $this->cart->getCart();

            // Compare with existing session data
            $billingDataChanged = $this->compareData->compareData($sessionData['billing'] ?? [], $billingData);
            $shippingDataChanged = $this->compareData->compareData($sessionData['shipping'] ?? [], $shippingData);
            $cartDataChanged = $this->compareData->compareCartData($sessionData['order']['cart'] ?? [], $cart);

            // Update session if any data has changed
            if ($billingDataChanged || $shippingDataChanged || $cartDataChanged) {
                $session = $this->updateSession->updateSession($sessionId, $fallbackEmail);
            }

            return $session;
        } catch (\Exception $e) {
            // Log and throw exception on error
            $this->logger->error('Error reinitialising session: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \Exception('Error reinitialising session');
        }
    }
}
