<?php

namespace Briqpay\Payments\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Briqpay\Payments\Model\Utility\ReinitialiseSession;
use Briqpay\Payments\Model\PaymentModule\CreateSession;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Logger\Logger;

class Session extends Action
{
    protected $resultJsonFactory;
    protected $createSession;
    protected $reinitialiseSession;
    protected $readSession;
    protected $logger;
    protected $sessionManager;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CreateSession $createSession,
        ReinitialiseSession $reinitialiseSession,
        ReadSession $readSession,
        Logger $logger,
        SessionManagerInterface $sessionManager
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->createSession = $createSession;
        $this->reinitialiseSession = $reinitialiseSession;
        $this->readSession = $readSession;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
    }

    public function execute()
    {
        $guestEmailEncoded = $_GET["hash"];
        $guestEmail = base64_decode($guestEmailEncoded);

        try {
            $sessionId = $this->sessionManager->getData('briqpay_session_id');
            if ($sessionId) {
                $session = $this->reinitialiseSession->reinitialiseSession($sessionId, $guestEmail);
            } else {
                $session = $this->createSession->getPaymentModule($guestEmail);
                $this->sessionManager->start();
                $this->sessionManager->setData('briqpay_session_id', $session['sessionId']);
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception occurred: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $this->getErrorResponse();
        }

        return $this->getSuccessResponse($session['htmlSnippet']);
    }

    private function getErrorResponse()
    {
        return $this->resultJsonFactory->create()->setData([
            'error' => true,
            'message' => 'Unable to create session'
        ]);
    }

    private function getSuccessResponse($htmlSnippet)
    {
        $htmlSnippet = $this->removeScriptTag($htmlSnippet);
        return $this->resultJsonFactory->create()->setData([
            'message' => $htmlSnippet
        ]);
    }

    private function removeScriptTag($htmlSnippet)
    {
        // Remove the <script> tag from the HTML snippet
        $htmlSnippet = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $htmlSnippet);
        return $htmlSnippet;
    }
}
