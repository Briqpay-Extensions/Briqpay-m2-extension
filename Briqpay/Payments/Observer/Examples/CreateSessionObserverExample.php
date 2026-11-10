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
        $this->logger->debug('Observer executed. Initial body:', ['body' => $body]);

        if ($config['customer_type'] === 'business') {
            // Log before adding business data
            $this->logger->debug('Customer type is business. Adding company data.');

            // Hardcoded business data example
            $body['data']['company'] = [
                'cin' => '0000000000',
                'name' => 'Example Company AB'
            ];

            // Log after adding business data
            $this->logger->debug('Company data added to body:', ['body' => $body]);
        } else if ($config['customer_type'] === 'consumer') {
            // Log before adding consumer data
            $this->logger->debug('Customer type is consumer. Adding consumer data.');

            // Hardcoded consumer data example
            $body['data']['consumer'] = [
                'identificationNumber' => 'YYYYMMDDNNNN',
                'dateOfBirth' => '1970-01-01',
                'name' => 'John Smith'
            ];

            // Log after adding consumer data
            $this->logger->debug('Consumer data added to body:', ['body' => $body]);
        } else {
            // Log if customer type is neither business nor consumer
            $this->logger->debug('Customer type is neither business nor consumer. No additional data added.');
        }

        // Check if the email fields for billing and shipping are null and set them if needed
        if (isset($body['data']['billing']) && $body['data']['billing']['email'] === null) {
            $this->logger->debug('Billing email is null. Setting to hardcoded value.');
            $body['data']['billing']['email'] = 'customer@example.com';
        }

        if (isset($body['data']['shipping']) && $body['data']['shipping']['email'] === null) {
            $this->logger->debug('Shipping email is null. Setting to hardcoded value.');
            $body['data']['shipping']['email'] = 'customer@example.com';
        }

        // Log final state of the body
        $this->logger->debug('Final body before request:', ['body' => $body]);

        // Debug statement to check if the body contains company data
        if (isset($body['data']['company'])) {
            $this->logger->debug('Company data exists in body:', ['company' => $body['data']['company']]);
        } else {
            $this->logger->debug('Company data does not exist in body.');
        }

        // Set the modified body back to the observer event
        $observer->getEvent()->setData('body', $body);
    }
}
