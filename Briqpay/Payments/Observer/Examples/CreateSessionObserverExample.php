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

        if ($config['customer_type'] === 'business') {
            // Log before adding business data
            $this->logger->info('Customer type is business. Adding company data.');

            // Hardcoded business data example
            $body['data']['company'] = [
                'cin' => '5592495336',
                'name' => 'Briqpay AB'
            ];

            // Log after adding business data
            $this->logger->info('Company data added to body:', ['body' => $body]);
        } else if ($config['customer_type'] === 'consumer') {
            // Log before adding consumer data
            $this->logger->info('Customer type is consumer. Adding consumer data.');

            // Hardcoded consumer data example
            $body['data']['consumer'] = [
                'identificationNumber' => '197001011234',
                'dateOfBirth' => '1970-01-01',
                'name' => 'John Smith'
            ];

            // Log after adding consumer data
            $this->logger->info('Consumer data added to body:', ['body' => $body]);
        } else {
            // Log if customer type is neither business nor consumer
            $this->logger->info('Customer type is neither business nor consumer. No additional data added.');
        }

        // Check if the email fields for billing and shipping are null and set them if needed
        if (isset($body['data']['billing']) && $body['data']['billing']['email'] === null) {
            $this->logger->info('Billing email is null. Setting to hardcoded value.');
            $body['data']['billing']['email'] = 'example@test.com';
        }

        if (isset($body['data']['shipping']) && $body['data']['shipping']['email'] === null) {
            $this->logger->info('Shipping email is null. Setting to hardcoded value.');
            $body['data']['shipping']['email'] = 'example@test.com';
        }

        // Log final state of the body
        $this->logger->info('Final body before request:', ['body' => $body]);

        // Debug statement to check if the body contains company data
        if (isset($body['data']['company'])) {
            $this->logger->info('Company data exists in body:', ['company' => $body['data']['company']]);
        } else {
            $this->logger->info('Company data does not exist in body.');
        }

        // Set the modified body back to the observer event
        $observer->getEvent()->setData('body', $body);
    }
}
