<?php
namespace Briqpay\Payments\Controller\Decision;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Briqpay\Payments\Model\Utility\MakeDecisionLogic;
use Briqpay\Payments\Logger\Logger;

class Index extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var MakeDecisionLogic
     */
    protected $makeDecision;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Index constructor.
     *
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param MakeDecisionLogic $makeDecision
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        MakeDecisionLogic $makeDecision,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->makeDecision = $makeDecision;
        $this->logger = $logger;
    }

    /**
     * Controller action to handle decision logic.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $guestEmail = $this->guestEmail();
        $result = $this->resultJsonFactory->create();
        
        try {
            $postData = json_decode($this->getRequest()->getContent(), true);
            if (!isset($postData['sessionId'])) {
                throw new \InvalidArgumentException('Session ID is missing.');
            }
            
            $sessionId = $postData['sessionId'];
            // The approval the page's earlier purchase on the session got, if any.
            $approval = isset($postData['approval']) && is_string($postData['approval']) ? $postData['approval'] : null;
            $decision = $this->makeDecision->withApproval($approval)->makeDecision($sessionId, $guestEmail);
            $answer = ['decision' => $decision];
            // Also with a failed decision: the session may be marked with it.
            if ($this->makeDecision->givenApproval() !== null) {
                $answer['approval'] = $this->makeDecision->givenApproval();
            }
            if ($this->makeDecision->refusalForCustomer() !== null) {
                $answer['message'] = $this->makeDecision->refusalForCustomer();
            }

            return $result->setData($answer);
            
        } catch (\Throwable $e) {
            $this->logger->error('Exception occurred: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $this->getErrorResponse();
        }
    }

    /**
     * Returns an error response.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    protected function getErrorResponse()
    {
        $result = $this->resultJsonFactory->create();
        return $result->setData(['error' => true, 'message' => 'An error occurred.']);
    }

    /**
     * The guest's email, from the JSON request body; older custom frontends may send it
     * as the base64 "hash" query parameter.
     */
    private function guestEmail(): ?string
    {
        $request = $this->getRequest();
        $body = $request instanceof \Magento\Framework\App\Request\Http
            ? json_decode((string) $request->getContent(), true)
            : null;
        if (is_array($body) && isset($body['guestEmail']) && is_string($body['guestEmail'])) {
            return $body['guestEmail'] !== '' ? $body['guestEmail'] : null;
        }
        $hash = $this->getRequest()->getParam('hash');
        if (!is_string($hash) || $hash === '') {
            return null;
        }
        $email = base64_decode($hash, true);

        return $email !== false && $email !== '' ? $email : null;
    }
}
