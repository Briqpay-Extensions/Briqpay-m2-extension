<?php

// WebhookErrorObserverExample
// The purpose of this example is to demonstrate how to intentionally cancel/refund an order that is not able to be created
// This example should be used with care!

namespace Briqpay\Payments\Observer\Examples;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Rest\ApiClient;

class WebhookErrorObserverExample implements ObserverInterface
{
    protected $logger;
    protected $apiClient;
    protected $readSession;

    public function __construct(
        Logger $logger,
        ReadSession $readSession,
        ApiClient $apiClient
    ) {
        $this->logger = $logger;
        $this->readSession = $readSession;
        $this->apiClient = $apiClient;
    }

    public function execute(Observer $observer)
    {
        $sessionId = $observer->getEvent()->getData('session');
        $quoteId   = $observer->getEvent()->getData('quote');

        //
        //Check status / message with own logic
        //
        //Status being the result of the webhook / order creation
        $status   = $observer->getEvent()->getData('status');
        // The error message from the catchblock
        $message   = $observer->getEvent()->getData('message');


        if (!$sessionId) {
            return;
        }

        try {
            $rawResponse = $this->readSession->getSession($sessionId);
            
            // Log the structure for a second just to be 100% sure in the logs
            $this->logger->info('Briqpay Recovery: Raw Session Read', ['keys' => array_keys($rawResponse)]);

            // Standardize the data root
            $sessionData = $rawResponse['sessionData'] ?? $rawResponse;
            $innerData = $sessionData['data'] ?? [];

            // DEEP SEARCH FOR CAPTURES
            // Path 1: sessionData -> data -> captures
            $captures = $innerData['captures'] ?? [];

            // Path 2: sessionData -> data -> transactions -> [0] -> captures
            if (empty($captures) && isset($innerData['transactions'][0]['captures'])) {
                $captures = $innerData['transactions'][0]['captures'];
            }

            // Path 3: Direct from raw (depending on ApiClient parsing)
            if (empty($captures) && isset($rawResponse['captures'])) {
                $captures = $rawResponse['captures'];
            }

            if (!empty($captures)) {
                $firstCapture = reset($captures);
                $captureId = $firstCapture['captureId'] ?? null;
                $orderData = $innerData['order'] ?? ($sessionData['order'] ?? null);

                if ($captureId && $orderData) {
                    $this->processRefund($sessionId, $captureId, $orderData);
                } else {
                    $this->logger->error('Capture found but missing data details for refund.', [
                        'captureId_found' => (bool)$captureId,
                        'orderData_found' => (bool)$orderData
                    ]);
                }
            } else {
                // Double check status field as a fail-safe
                $orderStatus = $sessionData['moduleStatus']['payment']['orderStatus'] ?? '';
                if ($orderStatus === 'captured_full' || $orderStatus === 'captured_partial') {
                     $this->logger->warning('No captures array found, but moduleStatus indicates CAPTURED. Attempting to find CaptureId in transactions.');
                     // One last try to find a captureId in transactions if the array was empty
                     $captureId = $innerData['transactions'][0]['pspOrderManagementIds']['capture']['apiTransactionId'] ?? null;
                     if ($captureId) {
                         $this->processRefund($sessionId, $captureId, $innerData['order']);
                         return;
                     }
                }

                $this->processCancel($sessionId, $quoteId);
            }

        } catch (\Exception $e) {
            $this->logger->error('Observer Recovery Failed: ' . $e->getMessage());
        }
    }

    private function processCancel($sessionId, $quoteId)
    {
        $uri = '/v3/session/' . $sessionId . '/order/cancel';
        $body = ['data' => ['notes' => 'Auto-canceled via error observer. Quote: ' . $quoteId]];
        $this->logger->info('Decision: Attempting API Cancel.');
        $this->apiClient->request('POST', $uri, $body);
        $this->logger->info('Briqpay API cancel successful.');
    }

    private function processRefund($sessionId, $captureId, $orderData)
    {
        $uri = '/v3/session/' . $sessionId . '/order/refund';
        $body = [
            'captureId' => $captureId,
            'data' => ['order' => $orderData]
        ];
        $this->logger->info('Decision: Attempting API Refund.', ['captureId' => $captureId]);
        $this->apiClient->request('POST', $uri, $body);
        $this->logger->info('Briqpay API refund successful.');
    }
}