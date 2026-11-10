<?php
declare(strict_types=1);

namespace Briqpay\Payments\Model\HostedPage;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\OrderManagement\SessionLedger;
use Briqpay\Payments\Model\PaymentModule\ReadSession;
use Briqpay\Payments\Model\Utility\AssignBillingAddress;
use Briqpay\Payments\Model\Utility\AssignShippingAddress;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Briqpay\Payments\Model\Utility\ScopeHelper;
use Briqpay\Payments\Model\Utility\SessionOrderData;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Rest\ApiRequestException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A Briqpay payment link for an order created in the admin: a session for the order's
 * cart and addresses, with the payment decision off (no storefront answers it), and
 * Briqpay's hosted payment page for that session. The customer pays on the page; the
 * order webhook then moves the order from pending payment to processing, as it does
 * for any paid session (HandleWebhook).
 */
class CreatePaymentLink
{
    public const LINK_URL = 'briqpay_payment_link_url';
    public const LINK_ID = 'briqpay_payment_link_id';
    public const LINK_CREATED_AT = 'briqpay_payment_link_created_at';
    public const LINK_CUSTOMER_TYPE = 'briqpay_payment_link_customer_type';
    public const LINK_SESSIONS = 'briqpay_payment_link_sessions';
    /** Set on the payment of every order placed in the admin with Briqpay. */
    public const ADMIN_ORDER = 'briqpay_admin_order';

    /**
     * Whether the order was placed in the admin (paid through a link, or waiting for one).
     *
     * @param Order $order
     * @return bool
     */
    public static function placedInAdmin(Order $order): bool
    {
        $payment = $order->getPayment();

        return $payment !== null && (
            (bool) $payment->getAdditionalInformation(self::ADMIN_ORDER)
            || (string) $payment->getAdditionalInformation(self::LINK_URL) !== ''
        );
    }

    private ApiClient $apiClient;

    private Logger $logger;

    private SetupConfig $setupConfig;

    private GenerateCart $generateCart;

    private AssignBillingAddress $billingData;

    private AssignShippingAddress $shippingData;

    private CartRepositoryInterface $quoteRepository;

    private OrderRepositoryInterface $orderRepository;

    private StoreManagerInterface $storeManager;

    private ReadSession $readSession;

    private SessionOrderData $sessionOrderData;

    private ScopeHelper $scopeHelper;

    private \Magento\Quote\Model\ResourceModel\Quote $quoteResource;

    /** Whether the last link needed a new hosted page for an existing session. */
    private bool $pageMade = false;

    public function __construct(
        ApiClient $apiClient,
        Logger $logger,
        SetupConfig $setupConfig,
        GenerateCart $generateCart,
        AssignBillingAddress $billingData,
        AssignShippingAddress $shippingData,
        CartRepositoryInterface $quoteRepository,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        ReadSession $readSession,
        SessionOrderData $sessionOrderData,
        ScopeHelper $scopeHelper,
        ?\Magento\Quote\Model\ResourceModel\Quote $quoteResource = null
    ) {
        $this->quoteResource = $quoteResource
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Quote\Model\ResourceModel\Quote::class);
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->setupConfig = $setupConfig;
        $this->generateCart = $generateCart;
        $this->billingData = $billingData;
        $this->shippingData = $shippingData;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->storeManager = $storeManager;
        $this->readSession = $readSession;
        $this->sessionOrderData = $sessionOrderData;
        $this->scopeHelper = $scopeHelper;
    }

    /**
     * Creates (or replaces) the order's payment link.
     *
     * @param Order $order
     * @param string $customerType consumer or business
     * @return string the hosted page's URL
     * @throws LocalizedException when the order cannot take a payment link
     */
    public function create(Order $order, string $customerType): string
    {
        return $this->scopeHelper->inStore($order->getStoreId(), function () use ($order, $customerType): string {
            return $this->createInStore($order, $customerType);
        });
    }

    /**
     * Whether the order can take a payment link: a Briqpay order still waiting for its
     * payment. An order paid at Briqpay carries the payment method the customer chose
     * (SessionOrderData), whether it was paid in the checkout or through a link.
     *
     * @param Order $order
     * @return bool
     */
    public static function accepts(Order $order): bool
    {
        $payment = $order->getPayment();

        return $payment !== null
            && $payment->getMethod() === 'briqpay'
            && self::unpaid($order)
            && in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW], true);
    }

    /**
     * Whether a Briqpay order has never been paid: no payment method and no session
     * status from Briqpay reached it. Whatever its state, there is nothing of it at
     * Briqpay to capture, refund or cancel.
     *
     * @param Order $order
     * @return bool
     */
    public static function unpaid(Order $order): bool
    {
        return (string) $order->getData('briqpay_psp_display_name') === ''
            && (string) $order->getData('briqpay_session_status') === '';
    }

    private function createInStore(Order $order, string $customerType): string
    {
        if (!self::accepts($order)) {
            throw new LocalizedException(__('Only a Briqpay order still waiting for its payment can get a payment link.'));
        }
        if (!in_array($customerType, ['consumer', 'business'], true)) {
            throw new LocalizedException(__('The customer type must be consumer or business.'));
        }
        $existing = (string) $order->getData('briqpay_session_id');
        $open = $existing !== '' ? $this->openSession($existing) : null;
        if ($existing !== '' && $open === null && $this->isPaid($existing)) {
            throw new LocalizedException(__(
                'This order has already been paid through its payment link. Briqpay\'s notice of the payment moves '
                . 'the order on; if it has not, check the Briqpay backoffice.'
            ));
        }
        $linkType = (string) $order->getPayment()->getAdditionalInformation(self::LINK_CUSTOMER_TYPE);
        if ($open !== null && $linkType !== '' && $linkType !== $customerType) {
            // A link for the other customer type is a new session.
            $open = null;
        }

        try {
            $quote = $this->quoteRepository->get((int) $order->getQuoteId());
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new LocalizedException(__(
                'The order\'s cart is no longer stored (Magento removes old carts), so no payment link can be made '
                . 'or updated for it. An earlier link, if any, still pays the order.'
            ));
        }
        $cartData = $this->generateCart->getCartDataFor($quote);
        $grandTotal = MinorUnits::fromFloat((float) $order->getGrandTotal());
        if ($cartData['amountIncVat'] !== $grandTotal || $cartData['cart'] === []) {
            throw new LocalizedException(__(
                'The Briqpay cart (%1 %3) does not equal the order total (%2 %3), so the payment link was not '
                . 'created or updated. Check the order\'s lines and totals.',
                number_format($cartData['amountIncVat'] / 100, 2, '.', ''),
                number_format($grandTotal / 100, 2, '.', ''),
                (string) $order->getOrderCurrencyCode()
            ));
        }

        $body = $this->sessionBody($order, $quote, $cartData, $customerType);
        $payment = $order->getPayment();
        $this->pageMade = false;
        $link = $open !== null ? $this->refreshedLink($existing, $body, $order) : null;
        if ($link === null) {
            $open = null;
            $link = $this->newLink($body, $order);
        }
        $sessionId = $link['sessionId'];
        $url = $link['url'];

        if ($open === null) {
            $this->sessionOrderData->apply($order, $link['session']);
            // Every session this order ever had: a payment on a replaced one is flagged.
            $sessions = (array) ($payment->getAdditionalInformation(self::LINK_SESSIONS) ?? []);
            foreach (array_filter([$existing, $sessionId]) as $id) {
                if (!in_array($id, $sessions, true)) {
                    $sessions[] = $id;
                }
            }
            $payment->setAdditionalInformation(self::LINK_SESSIONS, $sessions);
        }
        $order->setData('briqpay_session_id', $sessionId);
        $payment->setAdditionalInformation(self::LINK_URL, $url);
        $payment->setAdditionalInformation(self::LINK_ID, $link['pageId']);
        $payment->setAdditionalInformation(self::LINK_CREATED_AT, gmdate('Y-m-d H:i:s'));
        $payment->setAdditionalInformation(self::LINK_CUSTOMER_TYPE, $customerType);
        if ($open !== null && !$this->pageMade) {
            $note = __(
                'Briqpay payment link updated (%1): %2. The order\'s details were sent to Briqpay again; '
                . 'the link is unchanged.',
                $customerType,
                $url
            );
        } elseif ($open !== null) {
            $note = __('Briqpay payment link created (%1): %2.', $customerType, $url);
        } elseif ($existing !== '') {
            $note = __(
                'Briqpay payment link created (%1): %2. The earlier link is no longer this order\'s; '
                . 'a payment made on it is reported on the order.',
                $customerType,
                $url
            );
        } else {
            $note = __('Briqpay payment link created (%1): %2.', $customerType, $url);
        }
        $order->addCommentToStatusHistory((string) $note);
        // The webhook finds the order through its quote, and accepts the session only
        // when the quote carries its id. The column alone is written: a repository save
        // would re-price and re-validate a cart that was converted long ago.
        $this->quoteResource->getConnection()->update(
            $this->quoteResource->getMainTable(),
            ['briqpay_session_id' => $sessionId],
            ['entity_id = ?' => (int) $quote->getId()]
        );
        $this->orderRepository->save($order);
        $this->logger->info('Briqpay payment link created.', [
            'orderIncrementId' => $order->getIncrementId(),
            'sessionId' => $sessionId,
        ]);

        return $url;
    }

    /**
     * The order's open session brought up to date, with its page. A session has one
     * hosted page, so the link stays the same: every link ever sent for the order pays
     * this session, which Briqpay completes once. Null when Briqpay refuses the update
     * or the page is lost, and a new session is needed.
     *
     * @param string $sessionId
     * @param array<string, mixed> $body
     * @param Order $order
     * @return array{sessionId: string, session: array<string, mixed>, url: string, pageId: string}|null
     */
    private function refreshedLink(string $sessionId, array $body, Order $order): ?array
    {
        $payment = $order->getPayment();
        try {
            // The update takes the order and the addresses; the customer type and the
            // company are the session's from its creation.
            $this->apiClient->request('PATCH', '/v3/session/' . $sessionId, [
                'data' => array_diff_key($body['data'], ['company' => true]),
            ]);
            $url = (string) $payment->getAdditionalInformation(self::LINK_URL);
            $pageId = (string) $payment->getAdditionalInformation(self::LINK_ID);
            if ($url === '') {
                // The session has no page on record: one is made for it, which is a new
                // link.
                $page = $this->apiClient->request('POST', '/v3/hosted-page', [
                    'sessionId' => $sessionId,
                    'config' => $this->pageConfig($order),
                ]);
                $url = (string) ($page['url'] ?? '');
                $pageId = (string) ($page['hostedPageId'] ?? '');
                $this->pageMade = true;
            }
        } catch (ApiRequestException $e) {
            $this->logger->warning('The payment link\'s session could not be updated; a new one is created.', [
                'sessionId' => $sessionId,
                'statusCode' => $e->getStatusCode(),
            ]);

            return null;
        }

        return $url === '' ? null : ['sessionId' => $sessionId, 'session' => [], 'url' => $url, 'pageId' => $pageId];
    }

    /**
     * A new session for the order and its hosted page.
     *
     * @param array<string, mixed> $body
     * @param Order $order
     * @return array{sessionId: string, session: array<string, mixed>, url: string, pageId: string}
     * @throws LocalizedException
     */
    private function newLink(array $body, Order $order): array
    {
        $session = $this->apiClient->request('POST', '/v3/session', $body);
        $sessionId = (string) ($session['sessionId'] ?? '');
        if ($sessionId === '') {
            throw new LocalizedException(__('Briqpay returned no session for the payment link.'));
        }
        $page = $this->apiClient->request('POST', '/v3/hosted-page', [
            'sessionId' => $sessionId,
            'config' => $this->pageConfig($order),
        ]);
        $url = (string) ($page['url'] ?? '');
        if ($url === '') {
            throw new LocalizedException(__('Briqpay returned no hosted page for the payment link.'));
        }

        return [
            'sessionId' => $sessionId,
            'session' => (array) $session,
            'url' => $url,
            'pageId' => (string) ($page['hostedPageId'] ?? ''),
        ];
    }

    /**
     * @param Order $order
     * @param mixed $quote
     * @param array{cart: array<int, array<string, mixed>>, amountIncVat: int, amountExVat: int} $cartData
     * @param string $customerType
     * @return array<string, mixed>
     */
    private function sessionBody(Order $order, $quote, array $cartData, string $customerType): array
    {
        $config = $this->setupConfig->getSetupConfig();
        $store = $this->storeManager->getStore($order->getStoreId());
        // The storefront's addresses and locale: this runs in the admin, whose URL
        // builder gives admin URLs (with the secret key) that Briqpay cannot reach, and
        // whose locale is the admin user's.
        $base = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK), '/') . '/';
        $webhookBaseUrl = $base . 'briqpay/webhooks/';
        $locale = (string) $this->scopeHelper->getScopedConfigValue('general/locale/code', ScopeInterface::SCOPE_STORE);
        $termsPage = trim((string) $this->scopeHelper->getScopedConfigValue(
            'payment/briqpay/terms_conditions_page',
            ScopeInterface::SCOPE_STORE
        ), '/');
        $billing = $this->billingData->getBillingDataFor($quote, (string) $order->getCustomerEmail());
        $shipping = $this->shippingData->getShippingDataFor($quote, (string) $order->getCustomerEmail());

        $body = [
            'country' => $config['country'],
            'locale' => $locale !== '' ? str_replace('_', '-', $locale) : $config['locale'],
            'customerType' => $customerType,
            'product' => [
                'type' => 'payment',
                'intent' => 'payment_one_time',
            ],
            'urls' => [
                'redirect' => $base . 'briqpay/order/paid/?order=' . rawurlencode((string) $order->getIncrementId()),
                'terms' => $base . $termsPage,
            ],
            'references' => [
                'reference1' => (string) $order->getIncrementId(),
                'orderId' => (string) $order->getIncrementId(),
                'quoteId' => (string) $quote->getId(),
                SessionLedger::LINE_AMOUNTS_REFERENCE => SessionLedger::LINE_AMOUNTS_MAGENTO,
            ],
            'data' => [
                'order' => [
                    'currency' => (string) $order->getOrderCurrencyCode(),
                    'amountIncVat' => $cartData['amountIncVat'],
                    'amountExVat' => $cartData['amountExVat'],
                    'cart' => $cartData['cart'],
                ],
                'billing' => $billing,
                'shipping' => $shipping,
            ],
            'hooks' => [
                [
                    'eventType' => 'order_status',
                    'statuses' => ['order_pending', 'order_rejected', 'order_approved_not_captured'],
                    'method' => 'POST',
                    'url' => $webhookBaseUrl,
                ],
                [
                    'eventType' => 'capture_status',
                    'statuses' => ['pending', 'rejected', 'approved'],
                    'method' => 'POST',
                    'url' => $webhookBaseUrl . 'capture',
                ],
            ],
            'modules' => [
                'loadModules' => ['payment'],
                // No storefront answers a decision for a payment link.
                'config' => ['payment' => ['decision' => ['enabled' => false]]],
            ],
        ];
        if ($customerType === 'business') {
            $company = trim((string) ($order->getBillingAddress() ? $order->getBillingAddress()->getCompany() : ''));
            if ($company === '') {
                throw new LocalizedException(__('A business payment link needs the company name on the billing address.'));
            }
            $body['data']['company'] = ['name' => $company];
            if (is_array($body['data']['billing'])) {
                $body['data']['billing']['companyName'] = $company;
            }
        }
        $body['data'] = array_filter($body['data'], static function ($block): bool {
            return $block !== null;
        });

        return $body;
    }

    /**
     * @param Order $order
     * @return array<string, mixed>
     */
    private function pageConfig(Order $order): array
    {
        $title = trim((string) $this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/payment_link_title'));
        if ($title === '') {
            $title = (string) $this->storeManager->getStore($order->getStoreId())->getFrontendName();
        }
        $config = ['showCart' => (bool) $this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/payment_link_show_cart')];
        if (mb_strlen($title) >= 3) {
            $config['pageTitle'] = mb_substr($title, 0, 256);
        }
        $logo = trim((string) $this->scopeHelper->getScopedConfigValue('payment/briqpay/advanced/payment_link_logo_url'));
        if ($logo !== '' && preg_match('#^https?://.+\.(png|jpe?g|svg)$#i', $logo)) {
            $config['logoUrl'] = substr($logo, 0, 512);
        }

        return $config;
    }

    /**
     * The order's session while it can still be paid, or null when it is completed,
     * rejected, cancelled, expired or no longer readable. Any other status is taken as
     * open: an update Briqpay refuses leads to a new session anyway.
     *
     * @param string $sessionId
     * @return array<string, mixed>|null
     */
    private function openSession(string $sessionId): ?array
    {
        try {
            $session = (array) $this->readSession->getSession($sessionId);
        } catch (\Exception $e) {
            $this->assertRefused($e);

            return null;
        }

        $status = (string) ($session['status'] ?? '');

        return $status === '' || in_array($status, ['completed', 'rejected', 'cancelled', 'expired'], true)
            ? null
            : $session;
    }

    private function isPaid(string $sessionId): bool
    {
        try {
            $session = (array) $this->readSession->getSession($sessionId);
        } catch (\Exception $e) {
            // An old link whose session Briqpay no longer knows: replaced.
            $this->assertRefused($e);

            return false;
        }

        return ($session['status'] ?? null) === 'completed';
    }

    /**
     * Only Briqpay's own refusal (an unknown session) means the session is gone; a
     * network or server failure must not lead to a second session beside a live one.
     *
     * @param \Exception $e
     * @throws LocalizedException
     */
    private function assertRefused(\Exception $e): void
    {
        if (!ApiRequestException::refusedIn($e)) {
            throw new LocalizedException(
                __(
                    'Briqpay could not answer for the order\'s session; try again. (%1)',
                    \Briqpay\Payments\Model\OrderManagement\CaptureOrder::readableError($e)
                ),
                $e
            );
        }
    }
}
