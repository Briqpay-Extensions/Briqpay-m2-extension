<?php
declare(strict_types=1);

namespace Briqpay\Payments\Controller\Order;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Where a customer lands after paying an order's payment link on Briqpay's hosted page:
 * a confirmation. The order itself is moved on by the webhook.
 */
class Paid extends Action implements HttpGetActionInterface
{
    private PageFactory $pageFactory;

    public function __construct(Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }

    /**
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Thank you for your payment'));

        return $page;
    }
}
