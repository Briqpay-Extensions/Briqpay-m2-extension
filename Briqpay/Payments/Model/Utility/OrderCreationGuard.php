<?php
/**
 * What both order paths (the customer's return and Briqpay's order webhook) must hold
 * before turning a quote into an order for a Briqpay payment.
 *
 * The two paths can run at the same moment for one payment, and Magento picks a new
 * order number when the reserved one is taken, so without a lock both would place an
 * order. A session is only paid for this quote when Briqpay says so: completed, a
 * transaction that was not rejected or cancelled, and created for this quote.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\OrderManagement\SessionLedger;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderCreationGuard
{
    /**
     * How long a second request for the same session waits for the first, in seconds.
     */
    private const LOCK_TIMEOUT = 30;

    /**
     * @var LockManagerInterface
     */
    private LockManagerInterface $lockManager;

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
     * How many of the module's own order placements are under way in this request.
     *
     * @var int
     */
    private int $placing = 0;

    /**
     * @var GenerateCart|null the cart as sent to Briqpay, for the order's quote
     */
    private ?GenerateCart $cart;

    /**
     * @var CompareData|null
     */
    private ?CompareData $compareData;

    /**
     * @var \Briqpay\Payments\Model\Config\SetupConfig|null
     */
    private ?\Briqpay\Payments\Model\Config\SetupConfig $setupConfig;

    /**
     * @param LockManagerInterface $lockManager
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Logger $logger
     * @param GenerateCart|null $cart
     * @param CompareData|null $compareData
     * @param \Briqpay\Payments\Model\Config\SetupConfig|null $setupConfig
     */
    public function __construct(
        LockManagerInterface $lockManager,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Logger $logger,
        ?GenerateCart $cart = null,
        ?CompareData $compareData = null,
        ?\Briqpay\Payments\Model\Config\SetupConfig $setupConfig = null
    ) {
        $this->lockManager = $lockManager;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
        $this->cart = $cart;
        $this->compareData = $compareData;
        $this->setupConfig = $setupConfig;
    }

    /**
     * Runs Magento's order placement for a payment Briqpay holds. Only placements made
     * through here are Briqpay orders placed on the storefront; any other (Magento's own
     * place-order call, a third-party checkout) has no payment and is refused (see
     * Observer\RefuseUnpaidPlacement).
     *
     * @param callable $submit
     * @return mixed what $submit returns
     */
    public function placing(callable $submit)
    {
        $this->placing++;
        try {
            return $submit();
        } finally {
            $this->placing--;
        }
    }

    /**
     * Whether one of the module's own order placements is under way.
     *
     * @return bool
     */
    public function isPlacing(): bool
    {
        return $this->placing > 0;
    }

    /**
     * Puts the order on hold when what Briqpay was paid for is not the cart the order was
     * made from: another amount (see holdIfAmountDiffers()), other lines at the same
     * amount, or, in a business shop, another company. A cart changed in another window
     * while a payment ran is ordered as it is now, and the merchant checks it first.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Quote\Model\Quote $quote the cart the order was made from
     * @param array<string,mixed> $session
     * @return void
     */
    public function holdIfSessionDiffers($order, $quote, array $session): void
    {
        $this->holdIfAmountDiffers($order, $session);
        $authorised = $session['data']['order']['amountIncVat'] ?? null;
        if ($authorised !== null && (int) $authorised !== MinorUnits::fromFloat((float) $order->getGrandTotal())) {
            // Held for the amount already, which also changes the lines: one note.
            return;
        }
        try {
            $differences = $this->differences($quote, $session);
        } catch (\Throwable $e) {
            // The order is saved already and must keep its Briqpay data: a cart that
            // cannot be compared is checked by the merchant instead.
            $this->logger->error('Could not compare the order with what Briqpay was paid for: ' . $e->getMessage(), [
                'orderIncrementId' => $order->getIncrementId(),
                'exception' => $e,
            ]);
            $order->addCommentToStatusHistory((string) __(
                'The order could not be compared with what Briqpay was paid for. The order is on hold; '
                . 'check the payment in the Briqpay backoffice before releasing it.'
            ));
            if ($order->canHold()) {
                $order->hold();
            }

            return;
        }
        if ($differences === []) {
            return;
        }
        $this->logger->warning('Briqpay was paid for another cart than the order was made from.', [
            'orderIncrementId' => $order->getIncrementId(),
            'differences' => $differences,
        ]);
        $order->addCommentToStatusHistory((string) __(
            'Briqpay was paid for a cart that differs from this order in %1: the cart changed while the '
            . 'payment was made. The order is on hold; check the payment in the Briqpay backoffice before '
            . 'releasing it.',
            implode(', ', $differences)
        ));
        if ($order->canHold()) {
            $order->hold();
        }
    }

    /**
     * What differs between the cart the order was made from and what Briqpay was paid for.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array<string,mixed> $session
     * @return array<int, string>
     */
    private function differences($quote, array $session): array
    {
        $differences = [];
        // Lines are compared for sessions this version built (Magento's own amounts).
        $exactLines = ($session['references'][SessionLedger::LINE_AMOUNTS_REFERENCE] ?? null)
            === SessionLedger::LINE_AMOUNTS_MAGENTO;
        if ($exactLines && $this->cart() !== null && $this->compareData() !== null
            && $this->compareData()->linesDiffer(
                (array) ($session['data']['order']['cart'] ?? []),
                (array) ($this->cart()->getCartDataFor($quote)['cart'] ?? [])
            )
        ) {
            $differences[] = (string) __('the order lines');
        }
        if ($this->setupConfig() !== null
            && ($this->setupConfig()->getSetupConfig()['customer_type'] ?? '') === 'business'
            && CompanyName::changedSince($quote, $session)
        ) {
            $differences[] = (string) __('the billing company');
        }

        return $differences;
    }

    /**
     * The cart builder, from Magento's object manager when not injected.
     *
     * @return GenerateCart|null
     */
    private function cart(): ?GenerateCart
    {
        return $this->cart ??= \Magento\Framework\App\ObjectManager::getInstance()->get(GenerateCart::class);
    }

    /**
     * The data comparison, from Magento's object manager when not injected.
     *
     * @return CompareData|null
     */
    private function compareData(): ?CompareData
    {
        return $this->compareData ??= \Magento\Framework\App\ObjectManager::getInstance()->get(CompareData::class);
    }

    /**
     * The shop's Briqpay settings, from Magento's object manager when not injected.
     *
     * @return \Briqpay\Payments\Model\Config\SetupConfig|null
     */
    private function setupConfig(): ?\Briqpay\Payments\Model\Config\SetupConfig
    {
        return $this->setupConfig ??= \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Briqpay\Payments\Model\Config\SetupConfig::class);
    }

    /**
     * Runs $create while no other request creates an order for the same Briqpay session.
     *
     * @param string $sessionId
     * @param callable $create
     * @param int|string|null $quoteId the cart, locked too when given
     * @return mixed what $create returns
     * @throws LocalizedException when another request holds the session for too long
     */
    public function withLock(string $sessionId, callable $create, $quoteId = null)
    {
        $name = 'briqpay_order_' . $sessionId;
        if (!$this->lockManager->lock($name, self::LOCK_TIMEOUT)) {
            throw new LocalizedException(__('The order for this payment is already being created.'));
        }
        // And the cart's: two paid sessions of one cart (a replaced one paid in another
        // tab) must not both pass the "no order yet" check.
        $cartLock = (int) $quoteId > 0 ? 'briqpay_order_quote_' . (int) $quoteId : null;
        if ($cartLock !== null && !$this->lockManager->lock($cartLock, self::LOCK_TIMEOUT)) {
            $this->lockManager->unlock($name);
            throw new LocalizedException(__('The order for this payment is already being created.'));
        }
        try {
            return $create();
        } finally {
            if ($cartLock !== null) {
                $this->lockManager->unlock($cartLock);
            }
            $this->lockManager->unlock($name);
        }
    }

    /**
     * The order already placed for the quote, read from the database, not the quote.
     *
     * @param int|string $quoteId
     * @return OrderInterface|null
     */
    public function existingOrder($quoteId): ?OrderInterface
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('quote_id', $quoteId)->setPageSize(1)->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($orders);

        return $order instanceof OrderInterface ? $order : null;
    }

    /**
     * Refuses a session that is not a payment for this quote.
     *
     * @param array<string,mixed> $session the session as read from Briqpay
     * @param int|string $quoteId
     * @return void
     * @throws LocalizedException
     */
    public function assertPaidFor(array $session, $quoteId): void
    {
        $sessionQuoteId = $session['references']['quoteId'] ?? null;
        if ($sessionQuoteId === null || (string) $sessionQuoteId !== (string) $quoteId) {
            $this->logger->warning('Briqpay session was not created for this quote.', [
                'sessionId' => $session['sessionId'] ?? null,
                'quoteId' => $quoteId,
                'sessionQuoteId' => $sessionQuoteId,
            ]);
            throw new LocalizedException(__('The Briqpay payment does not belong to this cart.'));
        }
        if (($session['status'] ?? null) !== 'completed') {
            throw new LocalizedException(__('The Briqpay payment is not completed.'));
        }
        $transaction = $session['data']['transactions'][0] ?? [];
        $orderStatus = $session['moduleStatus']['payment']['orderStatus'] ?? null;
        if (in_array($transaction['status'] ?? null, ['rejected', 'cancelled'], true)
            || in_array($orderStatus, ['order_rejected', 'order_cancelled'], true)
        ) {
            throw new LocalizedException(__('The Briqpay payment was rejected or cancelled.'));
        }
    }

    /**
     * Puts the order on hold, with a note, when its total is not what Briqpay authorised.
     *
     * The cart changed after the payment step, and the difference cannot be captured.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array<string,mixed> $session
     * @return void
     */
    public function holdIfAmountDiffers($order, array $session): void
    {
        $authorised = $session['data']['order']['amountIncVat'] ?? null;
        $currency = $session['data']['order']['currency'] ?? null;
        $total = MinorUnits::fromFloat((float) $order->getGrandTotal());
        if ($authorised === null
            || ((int) $authorised === $total && ($currency === null || $currency === $order->getOrderCurrencyCode()))
        ) {
            return;
        }
        $this->logger->warning('Briqpay authorised another amount than the order total.', [
            'orderIncrementId' => $order->getIncrementId(),
            'orderTotal' => $total,
            'authorised' => (int) $authorised,
            'authorisedCurrency' => $currency,
        ]);
        $order->addCommentToStatusHistory((string) __(
            'Briqpay authorised %1 %2, but the order total is %3 %4: the cart changed during payment. '
            . 'The order is on hold; check the payment in the Briqpay backoffice before releasing it.',
            number_format((int) $authorised / 100, 2, '.', ''),
            (string) $currency,
            number_format($total / 100, 2, '.', ''),
            (string) $order->getOrderCurrencyCode()
        ));
        if ($order->canHold()) {
            $order->hold();
        }
    }
}
