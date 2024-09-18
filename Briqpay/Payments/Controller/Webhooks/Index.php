<?php

namespace Briqpay\Payments\Controller\Webhooks;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Briqpay\Payments\Model\Utility\HandleWebhook;

/**
 * Class Index
 * Handles incoming webhook requests and processes order status updates.
 */
class Index extends Action implements CsrfAwareActionInterface
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var HandleWebhook
     */
    protected $handleWebhook;

    /**
     * Index constructor.
     *
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Logger $logger
     * @param HandleWebhook $handleWebhook
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Logger $logger,
        HandleWebhook $handleWebhook
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->handleWebhook = $handleWebhook;
    }

    /**
     * Create CSRF validation exception.
     *
     * This method is used to handle invalid CSRF requests.
     * For webhooks, we bypass this validation.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate CSRF token.
     *
     * Always returns true to bypass CSRF protection for webhook calls.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Execute the webhook controller action.
     *
     * This method processes incoming webhook requests and attempts to handle the
     * order status update based on the provided data.
     * In case of an error, it returns an HTTP 400 status with an error message.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultJsonFactory->create();

        try {
            // Retrieve the request body content
            $requestBody = $this->getRequest()->getContent();
            // Decode the JSON content to an array
            $data = json_decode($requestBody, true);

            // Log the received data for debugging
            $this->logger->debug('Webhook received data: ' . print_r($data, true));

            // Process the webhook data using the service class
            $result = $this->handleWebhook->processOrderStatusWebhook($data);

            // Check if processing resulted in an error
            if (isset($result['status']) && !$result['status']) {
                // Log the error message
                $this->logger->error('Webhook processing failed with message: ' . $result['message']);
                // Return 400 status code with the result payload
                return $resultJson->setHttpResponseCode(400)->setData($result);
            }

            // If successful, return 200 status code with the result
            return $resultJson->setData($result);
        } catch (\Exception $e) {
            // Log the critical error and return 400 with the exception message
            $this->logger->critical('Webhook error: ' . $e->getMessage());
            return $resultJson->setHttpResponseCode(400)->setData(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
