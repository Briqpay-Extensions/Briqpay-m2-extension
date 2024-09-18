<?php
namespace Briqpay\Payments\Controller\Order;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Session\SessionManagerInterface;
use Briqpay\Payments\Model\Utility\CreateOrder;
use Briqpay\Payments\Logger\Logger;

class Success extends Action
{
    protected $checkoutSession;
    protected $customerSession;
    protected $createOrder;
    protected $logger;
    protected $sessionManager;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CreateOrder $createOrder,
        Logger $logger,
        SessionManagerInterface $sessionManager
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->createOrder = $createOrder;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $this->createOrder->createOrder();
            // Redirect to the default order success page
         
            $this->sessionManager->unsetData('briqpay_session_id');
            return $this->_redirect('checkout/onepage/success');
        } catch (\Exception $e) {
            $this->sessionManager->unsetData('briqpay_session_id');
            $this->logger->critical($e->getMessage());
            $this->messageManager->addErrorMessage(__('Something went wrong while creating the order.'));
            return $this->_redirect('checkout/onepage/failure');
        }
    }
}
