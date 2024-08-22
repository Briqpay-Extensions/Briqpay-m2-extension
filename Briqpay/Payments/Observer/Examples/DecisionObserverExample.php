<?php
namespace Briqpay\Payments\Observer\Examples;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Briqpay\Payments\Model\Utility\CompareData;
use Briqpay\Payments\Logger\Logger;

class DecisionObserverExample implements ObserverInterface
{
    /**
     * @var LoggerInterface
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

        // Log the received data for debugging
        $this->logger->info('Observer executed', [
            'session' => $session,
            'validation' => $validation,
            'quote' => $quote
        ]);

        //Example to compare cart items
        //$cartDataChanged = $this->compareData->compareCartData($sessionData['order']['cart'] ?? [], $cart);

        // Perform validation logic here
        // For now, we set validation to false as a first step
        $validation = true;

        $observer->getEvent()->setData('validation', $validation);
        // Log the updated validation status
        $this->logger->info('Validation set to false by DecisionObserverExample', [
            'validation' => $validation
        ]);
    }
}
