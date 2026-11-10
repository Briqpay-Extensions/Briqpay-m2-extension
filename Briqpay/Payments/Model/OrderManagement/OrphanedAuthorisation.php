<?php
/**
 * Cancels at Briqpay a paid authorisation that Magento cannot turn into an order, so the
 * customer is not left charged without an order.
 *
 * Only when that is certain: the quote was not converted, no order exists for the
 * Briqpay session, Magento's own checks refuse the quote (see OrderBlockers), and
 * nothing has been captured. A failure for any other reason, such as a timeout, is
 * left alone, because the order may still be created. The quote then loses its
 * session id and reserved order number, so a new attempt starts a new session under a
 * new order number.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\OrderBlockers;
use Briqpay\Payments\Rest\ApiClient;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrphanedAuthorisation
{
    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var OrderBlockers
     */
    private OrderBlockers $orderBlockers;

    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $quoteRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * @param ApiClient $apiClient
     * @param OrderBlockers $orderBlockers
     * @param CartRepositoryInterface $quoteRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Logger $logger
     */
    public function __construct(
        ApiClient $apiClient,
        OrderBlockers $orderBlockers,
        CartRepositoryInterface $quoteRepository,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Logger $logger
    ) {
        $this->apiClient = $apiClient;
        $this->orderBlockers = $orderBlockers;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * Cancels the authorisation when Magento cannot create an order for it.
     *
     * @param \Magento\Quote\Model\Quote $quote the quote the session was paid for
     * @param string $sessionId
     * @param array<string,mixed> $session the session as Briqpay returns it
     * @return bool whether the authorisation was cancelled
     */
    public function cancelIfNoOrderPossible($quote, string $sessionId, array $session): bool
    {
        if (($session['status'] ?? null) !== 'completed' || !$quote->getId() || !$quote->getIsActive()
            || $this->orderExists('briqpay_session_id', $sessionId)
            || $this->orderExists('quote_id', $quote->getId())
        ) {
            return false;
        }
        if ((new SessionLedger($session))->captures() !== []) {
            return false;
        }
        $blockers = $this->orderBlockers->forQuote($quote);
        if ($blockers === []) {
            return false;
        }

        try {
            $response = $this->apiClient->request(
                'POST',
                '/v3/session/' . $sessionId . '/order/cancel',
                ['data' => []]
            );
        } catch (\Exception $e) {
            $this->logger->critical('Could not cancel an authorisation Magento cannot create an order for.', [
                'sessionId' => $sessionId,
                'quoteId' => $quote->getId(),
                'blockers' => $blockers,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
        if (isset($response['error'])) {
            $this->logger->critical('Authorisation without an order left open: its payment method cannot cancel.', [
                'sessionId' => $sessionId,
                'quoteId' => $quote->getId(),
                'blockers' => $blockers,
            ]);

            return false;
        }

        $quote->setData('briqpay_session_id', null);
        $quote->setData('reserved_order_id', null);
        $this->quoteRepository->save($quote);
        $this->logger->critical('Cancelled at Briqpay an authorisation Magento cannot create an order for.', [
            'sessionId' => $sessionId,
            'quoteId' => $quote->getId(),
            'blockers' => $blockers,
        ]);

        return true;
    }

    /**
     * Whether Magento already holds an order with this value, for the session or the quote.
     *
     * @param string $field
     * @param int|string $value
     * @return bool
     */
    private function orderExists(string $field, $value): bool
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter($field, $value)
            ->setPageSize(1)
            ->create();

        return $this->orderRepository->getList($criteria)->getItems() !== [];
    }
}
