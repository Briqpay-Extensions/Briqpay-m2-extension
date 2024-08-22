<?php

namespace Briqpay\Payments\Controller\Webhooks;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Briqpay\Payments\Model\Utility\HandleCaptureWebhook;

class Capture extends Action implements CsrfAwareActionInterface
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var HandleCaptureWebhook
     */
    protected $handleWebhook;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Logger $logger
     * @param HandleCaptureWebhook $HandleCaptureWebhook
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Logger $logger,
        HandleCaptureWebhook $handleWebhook
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->handleWebhook = $handleWebhook;
    }

    /**
     * Create CSRF validation exception
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|bool
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate CSRF
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Execute action based on request and return result
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        // Get the request body content
        $requestBody = $this->getRequest()->getContent();
        // Decode the JSON content
        $data = json_decode($requestBody, true);

        // Log the data and quoteId for debugging
        $this->logger->debug('Webhook received data: ' . print_r($data, true));

        // Process the webhook using the service class
        $result = $this->handleWebhook->processCaptureStatusWebhook($data);

        // Return JSON response with HTTP status code 200
        $resultJson = $this->resultJsonFactory->create();
        return $resultJson->setData($result);
    }
}
