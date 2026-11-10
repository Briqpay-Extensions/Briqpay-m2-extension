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
     * @var Logger
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
        $resultJson = $this->resultJsonFactory->create();

        // Get the request body content
        $requestBody = $this->getRequest()->getContent();
        // Decode the JSON content
        $data = json_decode($requestBody, true);
        if (!is_array($data) || !self::validIds($data)) {
            $this->logger->warning('Capture webhook without a JSON body holding sessionId and quoteId ignored.');

            return $resultJson->setHttpResponseCode(400)
                ->setData(['status' => false, 'message' => 'The webhook could not be processed.']);
        }

        // Log the data and quoteId for debugging
        $this->logger->debug('Webhook received data', ['data' => $data]);

        // Process the webhook using the service class
        $result = $this->handleWebhook->processCaptureStatusWebhook($data);

        // A failure is answered as one, so it is visible to Briqpay.
        if (isset($result['status']) && !$result['status']) {
            $this->logger->error('Capture webhook processing failed: ' . ($result['message'] ?? ''));

            return $resultJson->setHttpResponseCode(400)
                ->setData(['status' => false, 'message' => 'The webhook could not be processed.']);
        }

        // Success is answered without the handler's details.
        return $resultJson->setData(['status' => true]);
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
            && (int) $data['quoteId'] > 0
            && self::validCapture($data);
    }

    /**
     * The capture id and status as Briqpay sends them: a refused capture may come
     * without an id, but whatever comes is a short plain string, as it ends up in the
     * order's history.
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    private static function validCapture(array $data): bool
    {
        $captureId = $data['captureId'] ?? null;
        $status = $data['status'] ?? null;

        return ($captureId === null || (is_string($captureId) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $captureId) === 1))
            && ($status === null || (is_string($status) && preg_match('/^[a-z_]{1,32}$/', $status) === 1));
    }
}
