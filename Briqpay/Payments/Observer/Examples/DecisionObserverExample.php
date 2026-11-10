<?php
namespace Briqpay\Payments\Observer\Examples;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Briqpay\Payments\Model\Utility\CompareData;
use Briqpay\Payments\Logger\Logger;

class DecisionObserverExample implements ObserverInterface
{
    /**
     * @var Logger
     */
    protected $logger;
    protected $compareData;

    /**
     * DecisionObserverExample constructor.
     *
     * @param Logger $logger
     */
    public function __construct(
        Logger $logger,
        CompareData $compareData,
    )
    {
        $this->logger = $logger;
        $this->compareData = $compareData;
    }

    /**
     * Example method to execute when the event is dispatched.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $session = $observer->getEvent()->getSession();
        $validation = $observer->getEvent()->getValidation();
        $quote = $observer->getEvent()->getQuote();

        $this->logger->debug('Decision observer called', [
            'sessionId' => $session['sessionId'] ?? null,
            'validation' => $validation,
            'quoteId' => $quote ? $quote->getId() : null,
        ]);

        // Your own checks go here; false refuses the purchase.
        $validation = true;

        $observer->getEvent()->setData('validation', $validation);
    }
}
