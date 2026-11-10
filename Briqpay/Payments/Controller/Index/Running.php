<?php

namespace Briqpay\Payments\Controller\Index;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\PaymentModule\UpdateReference;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;

/**
 * Answers whether a purchase on the cart's Briqpay session is being paid, in any tab.
 * The checkout asks before it locks the payment window, as a payment may be running in
 * another tab.
 */
class Running extends Action
{
    private JsonFactory $resultJsonFactory;
    private CheckoutSession $checkoutSession;
    private SessionManagerInterface $sessionManager;
    private ReadSession $readSession;
    private QuoteResource $quoteResource;
    private Logger $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        SessionManagerInterface $sessionManager,
        ReadSession $readSession,
        QuoteResource $quoteResource,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->sessionManager = $sessionManager;
        $this->readSession = $readSession;
        $this->quoteResource = $quoteResource;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $running = false;
        try {
            $sessionId = $this->sessionId();
            if ($sessionId !== null) {
                $session = $this->readSession->getSession($sessionId);
                // Only a session made for this cart counts (the customer's session can
                // still name an earlier cart's).
                $running = (string) ($session['references']['quoteId'] ?? '')
                        === (string) $this->checkoutSession->getQuoteId()
                    && UpdateReference::decisionRunning($session);
            }
        } catch (\Throwable $e) {
            // Unknown: the page locks as it would without the question.
            $this->logger->warning('Could not tell whether a Briqpay payment is running.', [
                'exception' => $e,
            ]);
        }

        return $this->resultJsonFactory->create()->setData(['paymentRunning' => $running]);
    }

    /**
     * The cart's session: the one stored on the quote, read from the database now (another
     * tab may just have stored it), else the one in the customer's session.
     *
     * @return string|null
     */
    private function sessionId(): ?string
    {
        $quoteId = $this->checkoutSession->getQuoteId();
        if ($quoteId) {
            $connection = $this->quoteResource->getConnection();
            $stored = $connection->fetchOne(
                $connection->select()
                    ->from($this->quoteResource->getMainTable(), ['briqpay_session_id'])
                    ->where('entity_id = ?', $quoteId)
            );
            if ($stored) {
                return (string) $stored;
            }
        }
        $sessionId = $this->sessionManager->getData('briqpay_session_id');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }
}
