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
            $session = $this->readSession->getSession($sessionId);
            $captures = (array) ($session['data']['captures'] ?? []);
            $order = $session['data']['order'] ?? null;

            // A captured payment is refunded; an uncaptured one is cancelled.
            if ($captures !== []) {
                $captureId = reset($captures)['captureId'] ?? null;
                if ($captureId && $order) {
                    $this->processRefund($sessionId, $captureId, $order);
                } else {
                    $this->logger->error('Capture found but its id or the order is missing; nothing refunded.');
                }
            } else {
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