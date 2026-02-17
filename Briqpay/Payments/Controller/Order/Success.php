<?php

namespace Briqpay\Payments\Controller\Order;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\CreateOrder;
use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;

class Success implements HttpGetActionInterface
{
    protected $checkoutSession;
    protected $customerSession;
    protected $createOrder;
    protected $logger;
    protected $quoteRepository;
    protected $sessionManager;
    private RedirectFactory $redirectFactory;
    private ManagerInterface $messageManager;

    public function __construct(
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CreateOrder $createOrder,
        Logger $logger,
        CartRepositoryInterface $quoteRepository,
        SessionManagerInterface $sessionManager,
        ManagerInterface $messageManager,
        RedirectFactory $redirectFactory
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->createOrder = $createOrder;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->sessionManager = $sessionManager;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
    }

    public function execute()
    {
        try {
            $this->createOrder->createOrder();
            // Redirect to the default order success page

            $this->sessionManager->unsetData('briqpay_session_id');

            return $this->redirectFactory->create()->setPath('checkout/onepage/success');
        } catch (Exception $e) {
            //Unset Briqpay sessionid on qoute
            $quote = $this->checkoutSession->getQuote();
            $quote->setData('briqpay_session_id', null);
            $this->quoteRepository->save($quote);
            //Unset Briqpay sessionid on m2 session
            $this->sessionManager->unsetData('briqpay_session_id');
            $this->logger->critical($e->getMessage());
            $this->messageManager->addErrorMessage(__('Something went wrong while creating the order.'));
            return $this->redirectFactory->create()->setPath('checkout/onepage/failure');
        }
    }
}
