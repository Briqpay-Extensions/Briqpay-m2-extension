<?php

namespace Briqpay\Payments\Model\PaymentModule;

use Briqpay\Payments\Model\OrderManagement\SessionLedger;
use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\Utility\CompanyName;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Quote\Model\QuoteRepository;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Framework\Exception\LocalizedException;

class UpdateReference
{
    /**
     * How long after an approved decision the checkout treats the session's payment as
     * possibly still running (a bank app, a strong authentication), in seconds. It also
     * bounds how long a payment abandoned in another window holds the checkout back.
     */
    public const DECISION_WINDOW = 900;

    /**
     * The references the last updateReference() wrote.
     *
     * @var array<string, string>
     */
    private array $written = [];

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

    /**
     * Whether a purchase on the session was approved within the decision window and the
     * page that paid has not said it ended: its payment may still be running, in any tab.
     *
     * @param array<string, mixed> $session the session as Briqpay returns it
     * @return bool
     */
    public static function decisionRunning(array $session): bool
    {
        $decidedAt = (int) ($session['references']['decidedAt'] ?? 0);

        return $decidedAt > 0 && time() - $decidedAt < self::DECISION_WINDOW
            && ($session['status'] ?? null) !== 'completed';
    }

    /**
     * @param string $sessionId
     * @param int $quoteId
     * @param array<string, mixed> $references the session's current references, kept
     * @param string|null $decidedAt the approval's token; now when not given
     * @return bool
     */
    public function updateReference(
        string $sessionId,
        int $quoteId,
        array $references = [],
        ?string $decidedAt = null
    ): bool
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

            // The company the session was created for is kept; references other modules
            // added are not sent again.
            $kept = array_map('strval', array_filter(
                array_intersect_key($references, [CompanyName::REFERENCE => true]),
                'is_scalar'
            ));
            $body = [
                'references' => array_merge($kept, [
                    'reference1' => (string) $quote->getReservedOrderId(),
                    'quoteId' => (string) $quoteId,
                    // When the purchase was approved: the checkout leaves a session with
                    // a payment running alone (another tab must not replace or lock it).
                    'decidedAt' => $decidedAt ?? (string) time(),
                    SessionLedger::LINE_AMOUNTS_REFERENCE => SessionLedger::LINE_AMOUNTS_MAGENTO,
                ]),
            ];

            // Send request to Briqpay
            $response = $this->apiClient->request('PATCH', $uri, $body);
            $this->written = $body['references'];

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error updating reference: ' . $e->getMessage(), [
                'exception' => $e,
                'sessionId' => $sessionId,
            ]);
            throw new \Exception('Error updating reference for session', 0, $e);
        }
    }

    /**
     * The references the last updateReference() wrote; decidedAt is the approval's token.
     *
     * @return array<string, string>
     */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * Marks the session's approved purchase as ended without an order, so the checkout
     * updates the session again. All references are resent with it.
     *
     * @param string $sessionId
     * @param array<string, mixed> $references the session's current references
     * @return void
     */
    public function endDecision(string $sessionId, array $references): void
    {
        $references['decidedAt'] = '0';
        $this->apiClient->request('PATCH', '/v3/session/' . $sessionId . '/metadata', [
            'references' => array_map('strval', array_filter($references, 'is_scalar')),
        ]);
    }
}
