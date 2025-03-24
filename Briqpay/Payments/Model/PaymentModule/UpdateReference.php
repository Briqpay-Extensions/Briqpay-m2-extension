<?php

namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Quote\Model\QuoteRepository;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Framework\Exception\LocalizedException;

class UpdateReference
{
    private SetupConfig $setupConfig;
    private ApiClient $apiClient;
    protected $logger;
    private QuoteRepository $quoteRepository;
    private CartRepositoryInterface $cartRepository;
    private QuoteManagement $quoteManagement;
    private QuoteIdMaskFactory $quoteIdMaskFactory;

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger,
        QuoteRepository $quoteRepository,
        CartRepositoryInterface $cartRepository,
        QuoteManagement $quoteManagement,
        QuoteIdMaskFactory $quoteIdMaskFactory
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->cartRepository = $cartRepository;
        $this->quoteManagement = $quoteManagement;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
    }

    public function updateReference(string $sessionId, int $quoteId): bool
    {
        try {
            $uri = '/v3/session/' . $sessionId . '/metadata';

            // Load Quote
            $quote = $this->cartRepository->get($quoteId);
            if (!$quote->getId()) {
                throw new LocalizedException(__('Quote not found.'));
            }

            // Reserve Order ID
            $quote->reserveOrderId();
            $this->quoteRepository->save($quote);

            // Prepare request body
            $body = [
                'references' => [
                    'reference1' => (string) $quote->getReservedOrderId(),
                    'quoteId' => (string) $quoteId,
                ]
            ];

            // Send request to Briqpay
            $response = $this->apiClient->request('PATCH', $uri, $body);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error updating reference: ' . $e->getMessage(), [
                'exception' => $e,
                'sessionId' => $sessionId,
            ]);
            throw new \Exception('Error updating reference for session', 0, $e);
        }
    }
}
