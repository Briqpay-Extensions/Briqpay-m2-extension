<?php

namespace Briqpay\Payments\Observer\Examples;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Briqpay\Payments\Logger\Logger;

class CreateSessionObserverExample implements ObserverInterface
{
    protected $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $body = $observer->getEvent()->getData('body');
        $config = $observer->getEvent()->getData('config');
        $quote = $observer->getEvent()->getData('quote');

        // Log initial state of the body 
        $this->logger->info('Observer executed. Initial body:', ['body' => $body]);

        // Override if needed.

        // Set the modified body back to the observer event
        $observer->getEvent()->setData('body', $body);
    }
}
