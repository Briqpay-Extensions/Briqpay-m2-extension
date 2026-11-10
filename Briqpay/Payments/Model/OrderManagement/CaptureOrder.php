<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Briqpay\Payments\Model\PaymentModule\ReadSession;

/**
 * Captures invoiced items at Briqpay.
 *
 * Capture lines are portions of the lines Briqpay authorised at checkout (see
 * CaptureCartBuilder), with the same reference, product type, tax rate and unit price,
 * and never beyond what remains authorised.
 */
class CaptureOrder
{
    private $apiClient;
    private $logger;
    private $readSession;
    /**
     * @var CaptureCartBuilder
     */
    private $captureCartBuilder;

    /**
     * @var CartPreflight
     */
    private $cartPreflight;

    /**
     * @var \Briqpay\Payments\Model\Utility\ScopeHelper|null
     */
    private $scopeHelper;

    const ITEM_TYPE_SHIPPING = 'shipping_fee';
    const DEFAULT_QUANTITY_UNIT = 'pc';

    private ?\Briqpay\Payments\Model\CustomTableFactory $customTableFactory;

    public function __construct(
        ApiClient $apiClient,
        Logger $logger,
        ReadSession $readSession,
        CaptureCartBuilder $captureCartBuilder,
        ?CartPreflight $cartPreflight = null,
        ?\Briqpay\Payments\Model\Utility\ScopeHelper $scopeHelper = null,
        ?\Briqpay\Payments\Model\CustomTableFactory $customTableFactory = null
    ) {
        $this->customTableFactory = $customTableFactory;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->readSession = $readSession;
        $this->captureCartBuilder = $captureCartBuilder;
        $this->cartPreflight = $cartPreflight ?? new CartPreflight();
        $this->scopeHelper = $scopeHelper
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Briqpay\Payments\Model\Utility\ScopeHelper::class);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param iterable<\Magento\Framework\DataObject> $captureCart order items, each with the invoiced
     *        quantity in "qty" or "quantity"
     * @param float|string $captureAmount Magento's invoice total
     * @param bool $amountIsInvoiceTotal whether $captureAmount is the invoice's own total in the
     *        order currency; only then must the capture equal it
     * @return array<string, mixed>|null Briqpay's response, including captureId
     * @throws LocalizedException
     */
    public function capture($order, $captureCart, $captureAmount, bool $amountIsInvoiceTotal = false)
    {
        // Credentials and test mode are the order's store's, not the admin's.
        $capture = function () use ($order, $captureCart, $captureAmount, $amountIsInvoiceTotal) {
            return $this->captureInStore($order, $captureCart, $captureAmount, $amountIsInvoiceTotal);
        };

        return $this->scopeHelper->inStore($order->getStoreId(), $capture);
    }

    /**
     * See capture().
     *
     * @param \Magento\Sales\Model\Order $order
     * @param iterable<\Magento\Framework\DataObject> $captureCart
     * @param float|string $captureAmount
     * @param bool $amountIsInvoiceTotal
     * @return array<string, mixed>|null
     * @throws LocalizedException
     */
    private function captureInStore($order, $captureCart, $captureAmount, bool $amountIsInvoiceTotal)
    {
        $this->logger->info('Briqpay: Starting capture for Order #' . $order->getIncrementId());

        $briqpaySessionId = $order->getData('briqpay_session_id');
        if (!$briqpaySessionId) {
            throw new LocalizedException(__('This order has no Briqpay session, so it cannot be captured at Briqpay.'));
        }

        try {
            $ledger = new SessionLedger((array) $this->readSession->getSession($briqpaySessionId));
        } catch (\Exception $e) {
            $this->logger->error('Briqpay Capture Error: could not read the session: ' . $e->getMessage());
            throw new LocalizedException(__('Error capturing order: %1', $e->getMessage()));
        }

        $items = self::ledgerItems($ledger, $captureCart);
        // An earlier attempt Briqpay processed although its answer never arrived (a
        // timeout, a crash before the invoice was saved) holds the quantity this invoice
        // asks for: built as if that capture were not there, the invoice's lines are
        // compared to it, and it is used rather than captured again.
        $unlinked = $this->unlinkedCaptureIds($ledger, $order);
        $existing = null;
        if ($unlinked !== []) {
            $trial = $ledger->without($unlinked);
            $trialCart = $this->captureCartBuilder->build($trial, $items);
            $existing = $this->identicalCapture($ledger, $unlinked, $trialCart);
            if ($existing !== null) {
                $cart = $trialCart;
            }
        }
        if ($existing === null) {
            $cart = $this->captureCartBuilder->build($ledger, $items);
            $this->cartPreflight->checkCapture($ledger, $cart);
        }
        $body = [
            'data' => [
                'order' => self::orderTotals($cart) + [
                    'currency' => $order->getOrderCurrencyCode(),
                    'cart' => $cart,
                ],
            ],
        ];

        $expected = MinorUnits::fromFloat($captureAmount);
        if ($amountIsInvoiceTotal && $expected !== $body['data']['order']['amountIncVat']
            && self::mustMatchDocument($ledger, $items, $cart)
        ) {
            $this->logger->error('Briqpay: capture refused, it differs from the invoice total', [
                'orderIncrementId' => $order->getIncrementId(),
                'invoiceTotal' => $expected,
                'captureAmount' => $body['data']['order']['amountIncVat'],
            ]);
            throw new LocalizedException(__(
                'The Briqpay capture (%1) would not equal the invoice total (%2), so nothing was captured. '
                . 'Check the order in the Briqpay backoffice, or invoice offline.',
                number_format($body['data']['order']['amountIncVat'] / 100, 2, '.', ''),
                number_format($expected / 100, 2, '.', '')
            ));
        }
        if ($expected !== $body['data']['order']['amountIncVat'] && !$amountIsInvoiceTotal) {
            $this->logger->info('Briqpay: capture amount differs from the invoice total', [
                'orderIncrementId' => $order->getIncrementId(),
                'invoiceTotal' => $expected,
                'captureAmount' => $body['data']['order']['amountIncVat'],
            ]);
        }

        if ($body['data']['order']['amountIncVat'] === 0) {
            // Nothing to capture, for example an invoice of fully discounted items.
            $this->logger->info('Briqpay: nothing to capture for this invoice, no capture sent.', [
                'orderIncrementId' => $order->getIncrementId(),
            ]);
            $response = ['captureId' => null];
        } elseif ($existing !== null) {
            // Briqpay already holds an approved capture of exactly these lines with no
            // invoice: an earlier attempt whose answer never arrived (a timeout, a crash
            // before the invoice was saved), or a capture made in the Briqpay backoffice.
            // Either way it is this invoice's, and capturing again would take the money twice.
            $this->logger->warning('Briqpay: an approved capture of exactly these lines exists without an invoice; '
                . 'it is used for this invoice instead of capturing again.', [
                'orderIncrementId' => $order->getIncrementId(),
                'captureId' => $existing,
            ]);
            $order->addCommentToStatusHistory((string) __(
                'Invoice linked to Briqpay capture %1, which already held exactly these lines (an earlier attempt '
                . 'whose answer was lost, or a capture made in the Briqpay backoffice). Nothing was captured again.',
                $existing
            ));
            $response = ['captureId' => $existing];
        } else {
            try {
                $response = $this->apiClient->request(
                    'POST',
                    '/v3/session/' . $briqpaySessionId . '/order/capture',
                    $body
                );
            } catch (\Exception $e) {
                $this->logger->error('Briqpay Capture Error: ' . $e->getMessage());
                throw new LocalizedException(__('Error capturing order: %1', self::readableError($e)));
            }
        }

        if ($amountIsInvoiceTotal && $expected !== $body['data']['order']['amountIncVat']) {
            $this->reportDifference($order, $expected, $body['data']['order']['amountIncVat']);
        }
        if ($body['data']['order']['amountIncVat'] !== 0 && $this->captureCartBuilder->vatDifference() !== 0) {
            $this->reportVatDifference($order, $this->captureCartBuilder->vatDifference());
        }

        return $response;
    }

    /**
     * The approved captures no Magento invoice refers to.
     *
     * @param SessionLedger $ledger
     * @param mixed $order
     * @return array<int, string>
     */
    private function unlinkedCaptureIds(SessionLedger $ledger, $order): array
    {
        $ids = [];
        foreach ($ledger->captures() as $capture) {
            $captureId = (string) ($capture['captureId'] ?? '');
            if ($captureId !== '' && ($capture['status'] ?? null) === 'approved' && !$this->isLinked($captureId, $order)) {
                $ids[] = $captureId;
            }
        }

        return $ids;
    }

    /**
     * The one of the given captures holding exactly these lines, or null.
     *
     * @param SessionLedger $ledger
     * @param array<int, string> $captureIds
     * @param array<int, array<string, mixed>> $cart
     * @return string|null
     */
    private function identicalCapture(SessionLedger $ledger, array $captureIds, array $cart): ?string
    {
        $wanted = self::lineShape($cart);
        foreach ($ledger->captures() as $capture) {
            $captureId = (string) ($capture['captureId'] ?? '');
            if (in_array($captureId, $captureIds, true) && self::lineShape((array) ($capture['cart'] ?? [])) === $wanted) {
                return $captureId;
            }
        }

        return null;
    }

    /**
     * Whether a Magento invoice refers to the capture.
     *
     * @param string $captureId
     * @param mixed $order
     * @return bool
     */
    private function isLinked(string $captureId, $order): bool
    {
        if (method_exists($order, 'getInvoiceCollection')) {
            foreach ($order->getInvoiceCollection() as $invoice) {
                if ((string) $invoice->getTransactionId() === $captureId) {
                    return true;
                }
            }
        }
        $this->customTableFactory = $this->customTableFactory
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Briqpay\Payments\Model\CustomTableFactory::class);
        $collection = $this->customTableFactory->create()->getCollection();
        $collection->addFieldToFilter('capture_id', $captureId);

        return (int) $collection->getFirstItem()->getInvoiceId() !== 0
            || (int) $collection->getFirstItem()->getOrderId() !== 0;
    }

    /**
     * What identifies a set of lines: reference, type, quantity and amounts, in a fixed order.
     *
     * @param array<int, array<string, mixed>> $cart
     * @return array<int, string>
     */
    private static function lineShape(array $cart): array
    {
        $shape = [];
        foreach ($cart as $line) {
            $shape[] = implode('|', [
                (string) ($line['reference'] ?? ''),
                (string) ($line['productType'] ?? ''),
                (string) (float) ($line['quantity'] ?? 0),
                (string) (int) ($line['totalAmount'] ?? $line['totalAmountIncVat'] ?? 0),
                (string) (int) ($line['totalVatAmount'] ?? $line['totalTaxAmount'] ?? 0),
            ]);
        }
        sort($shape);

        return $shape;
    }

    /**
     * Tells the merchant that the capture's VAT differs from Magento's invoice.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param int $difference Briqpay's VAT less Magento's, minor units
     * @return void
     */
    private function reportVatDifference($order, int $difference): void
    {
        $this->logger->warning('Briqpay: captured VAT differs from the invoice', [
            'orderIncrementId' => $order->getIncrementId(),
            'vatDifference' => $difference,
        ]);
        $order->addCommentToStatusHistory((string) ($difference > 0
            ? __(
                'Briqpay captured %1 %2 more VAT than Magento\'s invoice shows, because each line\'s VAT must '
                . 'match its tax rate. The amount captured is unchanged.',
                number_format($difference / 100, 2, '.', ''),
                $order->getOrderCurrencyCode()
            )
            : __(
                'Briqpay captured %1 %2 less VAT than Magento\'s invoice shows, because each line\'s VAT must '
                . 'match its tax rate. The amount captured is unchanged.',
                number_format(-$difference / 100, 2, '.', ''),
                $order->getOrderCurrencyCode()
            )));
    }

    /**
     * The VAT of cart lines, minor units.
     *
     * @param array<int, array<string, mixed>> $cart
     * @return int
     */
    public static function vatTotal(array $cart): int
    {
        return array_sum(array_map(static function (array $line): int {
            return (int) ($line['totalVatAmount'] ?? 0);
        }, $cart));
    }

    /**
     * Tells the merchant when Briqpay captured another amount than the invoice total.
     *
     * A session created before 2.0 holds its own split of discounts, which can differ from
     * Magento's per invoice; Briqpay captures what it authorised, so the merchant is told
     * on the order rather than the capture changed.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param int $invoiceTotal minor units
     * @param int $captured minor units
     * @return void
     */
    private function reportDifference($order, int $invoiceTotal, int $captured): void
    {
        $this->logger->warning('Briqpay: captured amount differs from the invoice total', [
            'orderIncrementId' => $order->getIncrementId(),
            'invoiceTotal' => $invoiceTotal,
            'captureAmount' => $captured,
        ]);
        $text = (string) __(
            'Briqpay captured %1 %2 for this invoice, which Magento totals at %3 %2.',
            number_format($captured / 100, 2, '.', ''),
            $order->getOrderCurrencyCode(),
            number_format($invoiceTotal / 100, 2, '.', '')
        );
        $order->addCommentToStatusHistory($text);
        try {
            \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Message\ManagerInterface::class)
                ->addWarningMessage($text);
        } catch (\RuntimeException $e) {
            return;
        }
    }

    /**
     * The reference Briqpay holds an item's line under: its own, when the SKU was on
     * several lines of the cart (see GenerateCart::itemReference()), otherwise the SKU.
     *
     * @param SessionLedger $ledger
     * @param string $sku
     * @param int|string|null $quoteItemId the quote item the order item was made from
     * @return string
     */
    public static function itemReference(SessionLedger $ledger, string $sku, $quoteItemId): string
    {
        if ($quoteItemId) {
            $own = \Briqpay\Payments\Model\Utility\GenerateCart::itemReference($sku, $quoteItemId);
            if ($ledger->sessionLine($own, CaptureCartBuilder::ITEM_TYPES) !== null) {
                return $own;
            }
        }

        return substr($sku, 0, 64);
    }

    /**
     * The invoiced or credited quantities by the reference Briqpay holds them under.
     *
     * A dynamic bundle is sent per child since 2.0. A session from before that holds
     * the bundle as one line, so its children are taken back to the bundle there.
     *
     * @param SessionLedger $ledger
     * @param iterable<\Magento\Framework\DataObject> $documentItems items with "sku", the quantity in
     *        "qty" or "quantity", and optionally "charged_incl_vat", "parent_sku" and "parent_qty"
     * @return array<int, array{reference: string, quantity: float, chargedInclVat?: int, document?: array<string, int>}>
     */
    public static function ledgerItems(SessionLedger $ledger, iterable $documentItems): array
    {
        $items = [];
        $bundles = [];
        foreach ($documentItems as $item) {
            $qty = (float) ($item->getQty() ?: $item->getQuantity());
            $type = method_exists($item, 'getProductType') ? $item->getProductType() : '';
            if ($qty <= 0 || $type === 'shipping') {
                continue;
            }
            $reference = self::itemReference($ledger, (string) $item->getSku(), $item->getQuoteItemId());
            $parentReference = $item->getParentSku() !== null ? substr((string) $item->getParentSku(), 0, 64) : null;
            if ($parentReference !== null
                && $ledger->sessionLine($reference, CaptureCartBuilder::ITEM_TYPES) === null
                && $ledger->sessionLine($parentReference, CaptureCartBuilder::ITEM_TYPES) !== null
            ) {
                $bundles[$parentReference] = (float) $item->getParentQty();
                continue;
            }
            $entry = ['reference' => $reference, 'quantity' => $qty];
            if ($item->getChargedInclVat() !== null) {
                $entry['chargedInclVat'] = (int) $item->getChargedInclVat();
            }
            if (is_array($item->getDocumentAmounts())) {
                $entry['document'] = $item->getDocumentAmounts();
            }
            $items[] = $entry;
        }
        foreach ($bundles as $reference => $quantity) {
            $items[] = ['reference' => $reference, 'quantity' => $quantity];
        }

        return $items;
    }

    /**
     * A document line's own amounts, minor units: the line before discount incl. VAT and
     * its VAT, and the discount incl. VAT and its VAT, by the rule the checkout cart uses
     * (GenerateCart), so capture and refund lines are exactly Magento's.
     *
     * @param \Magento\Framework\DataObject $documentItem an invoice or credit memo item
     * @return array{grossInclVat: int, grossVat: int, discountInclVat: int, discountVat: int}|null
     *         null when the item lacks the amounts (it is then split by proportion)
     */
    public static function documentAmounts($documentItem): ?array
    {
        if ($documentItem->getRowTotalInclTax() === null || $documentItem->getRowTotal() === null) {
            return null;
        }
        $rowIncVat = MinorUnits::fromFloat((float) $documentItem->getRowTotalInclTax());
        $rowExVat = MinorUnits::fromFloat((float) $documentItem->getRowTotal());
        $tax = MinorUnits::fromFloat((float) $documentItem->getTaxAmount());
        $charged = self::chargedInclVat($documentItem);
        if (MinorUnits::fromFloat((float) $documentItem->getDiscountAmount()) === 0
            && MinorUnits::fromFloat((float) $documentItem->getDiscountTaxCompensationAmount()) === 0
        ) {
            // Magento rounds a partial line's incl.-VAT amount on its own, so it can be a
            // minor unit away from the excl.-VAT amount plus tax it charges; without a
            // discount the line is what Magento charges.
            return ['grossInclVat' => $charged, 'grossVat' => $tax, 'discountInclVat' => 0, 'discountVat' => 0];
        }

        $orderItem = $documentItem->getOrderItem();
        $vat = \Briqpay\Payments\Model\Utility\Money\VatSplit::split(
            $rowIncVat,
            $rowIncVat - $rowExVat,
            $rowIncVat - $charged,
            ($rowIncVat - $rowExVat) - $tax,
            MinorUnits::fromFloat((float) ($orderItem ? $orderItem->getTaxPercent() : $documentItem->getTaxPercent())),
            (float) $documentItem->getQty()
        );

        return [
            'grossInclVat' => $rowIncVat,
            'grossVat' => $vat['grossVat'],
            'discountInclVat' => $rowIncVat - $charged,
            'discountVat' => $vat['discountVat'],
        ];
    }

    /**
     * What Magento charges for a document line, incl. VAT and after its discount, in minor units.
     *
     * @param \Magento\Framework\DataObject $documentItem an invoice or credit memo item
     * @return int
     */
    public static function chargedInclVat($documentItem): int
    {
        return MinorUnits::fromFloat((float) $documentItem->getRowTotal())
            + MinorUnits::fromFloat((float) $documentItem->getTaxAmount())
            + MinorUnits::fromFloat((float) $documentItem->getDiscountTaxCompensationAmount())
            - MinorUnits::fromFloat((float) $documentItem->getDiscountAmount());
    }

    /**
     * Whether a capture or refund must equal its Magento document to the minor unit: the
     * session carries Magento's exact amounts, every line came with its document's
     * amounts, and no WEEE line is split by proportion.
     *
     * @param SessionLedger $ledger
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $cart
     * @return bool
     */
    public static function mustMatchDocument(SessionLedger $ledger, array $items, array $cart): bool
    {
        if (!$ledger->documentAmounts()) {
            return false;
        }
        foreach ($items as $item) {
            if (!isset($item['document'])) {
                return false;
            }
        }
        foreach ($cart as $line) {
            if (substr((string) ($line['reference'] ?? ''), -9) === '_weee_tax') {
                return false;
            }
        }

        return true;
    }

    /**
     * Briqpay's own error message from a failed API call, without the raw response.
     *
     * @param \Exception $error
     * @return string
     */
    public static function readableError(\Exception $error): string
    {
        $message = $error->getMessage();
        $start = strpos($message, '{');
        $data = $start === false ? null : json_decode(substr($message, $start), true);
        $briqpay = is_array($data) ? ($data['error']['message'] ?? null) : null;

        return is_string($briqpay) && $briqpay !== '' ? $briqpay : $message;
    }

    /**
     * Order totals as the exact sums of the lines sent with them.
     *
     * @param array<int, array<string, mixed>> $cart
     * @return array{amountIncVat: int, amountExVat: int}
     */
    public static function orderTotals(array $cart): array
    {
        $incVat = 0;
        $exVat = 0;
        foreach ($cart as $line) {
            $incVat += (int) $line['totalAmount'];
            $exVat += (int) $line['totalAmount'] - (int) $line['totalVatAmount'];
        }

        return ['amountIncVat' => $incVat, 'amountExVat' => $exVat];
    }
}
