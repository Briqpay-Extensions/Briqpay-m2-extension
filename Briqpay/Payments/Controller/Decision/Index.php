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
        $guestEmailEncoded = $_GET["hash"];
        $guestEmail = base64_decode($guestEmailEncoded);
        $result = $this->resultJsonFactory->create();
        
        try {
            $postData = json_decode($this->getRequest()->getContent(), true);
            if (!isset($postData['sessionId'])) {
                throw new \InvalidArgumentException('Session ID is missing.');
            }
            
            $sessionId = $postData['sessionId'];
            $decision = $this->makeDecision->makeDecision($sessionId, $guestEmail);

            return $result->setData(['decision' => $decision]);
            
        } catch (\Exception $e) {
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
}
