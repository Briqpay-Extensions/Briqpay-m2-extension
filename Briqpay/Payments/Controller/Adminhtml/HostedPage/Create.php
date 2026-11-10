<?php
declare(strict_types=1);

namespace Briqpay\Payments\Controller\Adminhtml\HostedPage;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\HostedPage\CreatePaymentLink;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * "Create payment link" on the admin order view.
 */
class Create extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_edit';

    private OrderRepositoryInterface $orderRepository;

    private CreatePaymentLink $createPaymentLink;

    private Logger $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        CreatePaymentLink $createPaymentLink,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->createPaymentLink = $createPaymentLink;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
        try {
            $order = $this->orderRepository->get($orderId);
            if (!$order instanceof Order) {
                throw new LocalizedException(__('Order not found.'));
            }
            $url = $this->createPaymentLink->create(
                $order,
                (string) $this->getRequest()->getParam('customer_type', 'consumer')
            );
            $this->messageManager->addSuccessMessage(__('Briqpay payment link: %1. See the order\'s comment history for what was done.', $url));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Payment link could not be made: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('The payment link could not be created. See the Briqpay log.'));
        }

        return $redirect;
    }
}
