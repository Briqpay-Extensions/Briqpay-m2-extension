<?php

namespace Briqpay\Payments\Controller\Webhooks;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface;
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

    protected $eventManager;

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
         ManagerInterface $eventManager,
        HandleWebhook $handleWebhook
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->eventManager = $eventManager;
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
            if (!is_array($data) || !self::validIds($data)) {
                $this->logger->warning('Webhook without a JSON body holding sessionId and quoteId ignored.');

                return $resultJson->setHttpResponseCode(400)
                    ->setData(['status' => false, 'message' => 'The webhook could not be processed.']);
            }

            // Log the received data for debugging
            $this->logger->debug('Webhook received data', ['data' => $data]);

            // Process the webhook data using the service class
            $result = $this->handleWebhook->processOrderStatusWebhook($data);

            // Check if processing resulted in an error
            if (isset($result['status']) && !$result['status']) {
                $this->eventManager->dispatch('briqpay_payment_module_webhook_error', [
                    'session' => $data['sessionId'],
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'quote' => $data['quoteId']
                 ]);
                // Log the error message
                $this->logger->error('Webhook processing failed with message: ' . $result['message']);
                // The reason stays in the log; the answer only says it failed.
                return $resultJson->setHttpResponseCode(400)
                    ->setData(['status' => false, 'message' => 'The webhook could not be processed.']);
            }

            // Success is answered without the handler's details.
            return $resultJson->setData(['status' => true]);
        } catch (\Exception $e) {
            // Log the critical error and return 400 with the exception message
            $this->logger->critical('Webhook error: ' . $e->getMessage());
            return $resultJson->setHttpResponseCode(400)
                ->setData(['status' => false, 'message' => 'The webhook could not be processed.']);
        }
    }

    /**
     * A session id and a quote id as Briqpay sends them; anything else is answered
     * without touching the API or the database.
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    private static function validIds(array $data): bool
    {
        return isset($data['sessionId'], $data['quoteId'])
            && is_string($data['sessionId'])
            && preg_match('/^[A-Za-z0-9-]{1,64}$/', $data['sessionId']) === 1
            && (is_int($data['quoteId']) || (is_string($data['quoteId']) && ctype_digit($data['quoteId'])))
            && (int) $data['quoteId'] > 0;
    }
}
