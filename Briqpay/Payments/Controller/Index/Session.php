<?php

namespace Briqpay\Payments\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Briqpay\Payments\Model\Utility\ReinitialiseSession;
use Briqpay\Payments\Model\PaymentModule\CreateSession;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Logger\Logger;
use Magento\Quote\Api\CartRepositoryInterface;

class Session extends Action
{
    protected $resultJsonFactory;
    protected $createSession;
    protected $reinitialiseSession;
    protected $checkoutSession;
    protected $readSession;
    protected $logger;
    protected $sessionManager;
    protected $quoteRepository;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CreateSession $createSession,
        ReinitialiseSession $reinitialiseSession,
        CheckoutSession $checkoutSession,
        ReadSession $readSession,
        Logger $logger,
        SessionManagerInterface $sessionManager,
        CartRepositoryInterface $quoteRepository
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->createSession = $createSession;
        $this->reinitialiseSession = $reinitialiseSession;
        $this->checkoutSession = $checkoutSession;
        $this->readSession = $readSession;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
        $this->quoteRepository = $quoteRepository;
    }

    public function execute()
    {
        $guestEmailEncoded = $_GET["hash"];
        $guestEmail = base64_decode($guestEmailEncoded);

        try {
            $sessionId = $this->sessionManager->getData('briqpay_session_id');
            $quoteId = $this->checkoutSession->getQuoteId();

            if ($sessionId) {
                $readSessionData = $this->readSession->getSession($sessionId);
            }
            $triggerNewSession = false;

            if ($sessionId && ($quoteId != $readSessionData['references']['quoteId'])) {
                $this->logger->info('Quotes did not match, starting new session');
                $triggerNewSession = true;
            }

            if ($sessionId && !$triggerNewSession) {
                $session = $this->reinitialiseSession->reinitialiseSession($sessionId, $guestEmail);
            } else {
                $quoteId = $this->checkoutSession->getQuoteId();
                $quote = $this->quoteRepository->get($quoteId);
    
                $briqpaySessionId = $quote->getData('briqpay_session_id');
                if (!is_null($briqpaySessionId) && !$triggerNewSession) {
                    $session = $this->reinitialiseSession->reinitialiseSession($briqpaySessionId, $guestEmail);
                    $this->sessionManager->start();
                    $this->sessionManager->setData('briqpay_session_id', $briqpaySessionId);
                } else {
                    $session = $this->createSession->getPaymentModule($guestEmail);
                    $this->sessionManager->start();
                    $this->sessionManager->setData('briqpay_session_id', $session['sessionId']);
                }
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
