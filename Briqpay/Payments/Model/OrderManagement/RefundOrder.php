<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Briqpay\Payments\Model\PaymentModule\ReadSession;

/**
 * Refunds a credit memo at Briqpay, with one refund per capture it draws on.
 *
 * Refund lines are portions of the captured lines (see RefundCartBuilder), one refund
 * per capture, never more than that capture still holds. The
 * payment_capture_mapping quantities are still kept up to date for anything that
 * reads them.
 */
class RefundOrder
{
    private $apiClient;
    private $logger;
    protected $customTableFactory;
    private $readSession;
    /**
     * @var RefundCartBuilder
     */
    private $refundCartBuilder;

    /**
     * @var CartPreflight
     */
    private $cartPreflight;

    /**
     * @var \Magento\Framework\Message\ManagerInterface|null
     */
    private $messageManager;

    const DEFAULT_QUANTITY_UNIT = 'pc';

    /**
     * @var \Briqpay\Payments\Model\Utility\ScopeHelper|null
     */
    private $scopeHelper;

    public function __construct(
        ApiClient $apiClient,
        Logger $logger,
        CustomTableFactory $customTableFactory,
        ReadSession $readSession,
        RefundCartBuilder $refundCartBuilder,
        ?CartPreflight $cartPreflight = null,
        ?\Magento\Framework\Message\ManagerInterface $messageManager = null,
        ?\Briqpay\Payments\Model\Utility\ScopeHelper $scopeHelper = null
    ) {
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->customTableFactory = $customTableFactory;
        $this->readSession = $readSession;
        $this->refundCartBuilder = $refundCartBuilder;
        $this->cartPreflight = $cartPreflight ?? new CartPreflight();
        $this->messageManager = $messageManager;
        $this->scopeHelper = $scopeHelper
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Briqpay\Payments\Model\Utility\ScopeHelper::class);
    }

    public function refund(Creditmemo $creditmemo)
    {
        // Credentials and test mode are the order's store's, not the admin's.
        $refund = function () use ($creditmemo) {
            return $this->refundInStore($creditmemo);
        };

        return $this->scopeHelper->inStore($creditmemo->getOrder()->getStoreId(), $refund);
    }

    /**
     * See refund().
     *
     * @param Creditmemo $creditmemo
     * @return mixed
     * @throws LocalizedException
     */
    private function refundInStore(Creditmemo $creditmemo)
    {
        $order = $creditmemo->getOrder();
        // The credit memo has no number until Magento saves it, after the refund.
        $this->logger->info('Briqpay: Starting Refund for a credit memo on order #' . $order->getIncrementId(), [
            'creditmemoTotal' => $creditmemo->getGrandTotal(),
        ]);

        $briqpaySessionId = $order->getData('briqpay_session_id');

        if (MinorUnits::fromFloat((float) $creditmemo->getGrandTotal()) === 0
            && MinorUnits::fromFloat((float) $creditmemo->getShippingAmount()) === 0
            && MinorUnits::fromFloat((float) $creditmemo->getAdjustmentPositive()) === 0
            && MinorUnits::fromFloat((float) $creditmemo->getAdjustmentNegative()) === 0
        ) {
            // Nothing to refund, for example fully discounted items.
            $this->logger->info('Briqpay: nothing to refund for this credit memo, no refund sent.', [
                'orderIncrementId' => $order->getIncrementId(),
            ]);

            return null;
        }

        if (!$briqpaySessionId) {
            throw new LocalizedException(__('This order has no Briqpay session, so it cannot be refunded at Briqpay.'));
        }

        try {
            $ledger = new SessionLedger((array) $this->readSession->getSession($briqpaySessionId));
        } catch (\Exception $e) {
            $this->logger->error('Briqpay Refund API Error: could not read the session: ' . $e->getMessage());
            throw new LocalizedException(__('Error refunding: %1', $e->getMessage()));
        }

        $documentItems = [];
        $orderItemIds = [];
        $creditedQty = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $creditedQty[(int) $item->getOrderItemId()] = (float) $item->getQty();
        }
        foreach ($creditmemo->getAllItems() as $item) {
            $qty = (float) $item->getQty();
            $orderItem = $item->getOrderItem();
            if ($qty <= 0) {
                continue;
            }
            // An item credited at nothing (fully discounted) was captured at nothing, often
            // not at all, so there is nothing of it to refund.
            $document = CaptureOrder::documentAmounts($item);
            if ($document !== null && $document['grossInclVat'] - $document['discountInclVat'] === 0
                && $document['grossVat'] - $document['discountVat'] === 0
            ) {
                continue;
            }
            // Children are credited through their parent line, except a bundle priced
            // from its children (dynamic price), which is credited through them.
            $parent = $orderItem ? $orderItem->getParentItem() : null;
            if ($orderItem && $orderItem->getParentItemId() && !$parent) {
                continue;
            }
            $throughChildren = $parent
                ? $parent instanceof \Magento\Sales\Model\Order\Item && $parent->isChildrenCalculated()
                : ($orderItem && $orderItem->isChildrenCalculated());
            if ($parent ? !$throughChildren : $throughChildren) {
                continue;
            }
            $quoteItemId = $orderItem ? $orderItem->getQuoteItemId() : null;
            $reference = CaptureOrder::itemReference($ledger, (string) $item->getSku(), $quoteItemId);
            $documentItems[] = new \Magento\Framework\DataObject([
                'sku' => $item->getSku(),
                'quote_item_id' => $quoteItemId,
                'qty' => $qty,
                'charged_incl_vat' => CaptureOrder::chargedInclVat($item),
                'document_amounts' => CaptureOrder::documentAmounts($item),
                'parent_sku' => $parent ? $parent->getSku() : null,
                'parent_qty' => $parent ? ($creditedQty[(int) $parent->getItemId()] ?? null) : null,
            ]);
            $orderItemIds[$reference] = $item->getOrderItemId();
            if ($parent) {
                $orderItemIds[substr((string) $parent->getSku(), 0, 64)] = $parent->getItemId();
            }
        }
        $items = CaptureOrder::ledgerItems($ledger, $documentItems);

        $carts = $this->refundCartBuilder->build(
            $ledger,
            $items,
            self::creditedShipping($creditmemo),
            MinorUnits::fromFloat($creditmemo->getAdjustmentPositive())
                - MinorUnits::fromFloat($creditmemo->getAdjustmentNegative()),
            // Magento adds this credit memo to the order's refunded shipping before it
            // asks the payment method to refund.
            (float) $creditmemo->getShippingAmount() > 0
                && MinorUnits::fromFloat($order->getShippingRefunded())
                    >= MinorUnits::fromFloat($order->getShippingAmount()),
            $creditmemo->getInvoice() && $creditmemo->getInvoice()->getTransactionId()
                ? (string) $creditmemo->getInvoice()->getTransactionId()
                : null,
            $this->documentTotal($creditmemo, $ledger)
        );

        // Every cart is checked before any is sent, so a credit memo is never refunded in part.
        $this->cartPreflight->checkRefunds($ledger, $carts);
        $this->refuseDocumentMismatch($ledger, $items, $carts, $creditmemo);

        $refunded = 0;
        foreach ($carts as $captureId => $cart) {
            if (CaptureOrder::orderTotals($cart)['amountIncVat'] === 0) {
                // Nothing to refund from this capture, for example fully discounted items.
                continue;
            }
            $body = [
                'captureId' => (string) $captureId,
                'data' => [
                    'order' => CaptureOrder::orderTotals($cart) + [
                        'currency' => $order->getOrderCurrencyCode(),
                        'cart' => $cart,
                    ],
                ],
            ];

            $this->logger->info("Briqpay: Sending refund for Capture ID: $captureId", ['body' => $body]);

            try {
                $this->apiClient->request('POST', '/v3/session/' . $briqpaySessionId . '/order/refund', $body);
            } catch (\Exception $e) {
                $this->logger->error('Briqpay Refund API Error: ' . $e->getMessage());
                if ($refunded === 0) {
                    throw new LocalizedException(__(
                        'Error refunding capture %1: %2',
                        $captureId,
                        CaptureOrder::readableError($e)
                    ));
                }
                // Part of the credit memo is already refunded and cannot be taken back.
                // Refusing now would undo the credit memo in Magento while the customer
                // keeps that money, so it is saved and the rest is left to the merchant.
                $this->reportPartialRefund($order, (string) $captureId, $refunded, $e);
                break;
            }

            $refunded += $body['data']['order']['amountIncVat'];
            $this->updateCaptureMapping((string) $captureId, $cart, $orderItemIds);
        }

        $this->reportMoves($order);
        $this->reportReconciliation($order);
        $this->reportVatDifference($order);

        $expected = MinorUnits::fromFloat($creditmemo->getGrandTotal());
        if ($expected !== $refunded) {
            // What Briqpay refunds is what was captured for the credited lines. Magento's
            // total can differ, for example a credit memo total that is not a whole minor
            // unit, so the merchant is told on the order rather than the refund changed.
            $this->logger->warning('Briqpay: refunded amount differs from the credit memo total', [
                // The credit memo has no number until Magento saves it, after the refund.
                'orderIncrementId' => $order->getIncrementId(),
                'creditmemoTotal' => $expected,
                'refundedAmount' => $refunded,
            ]);
            $text = (string) __(
                'Briqpay refunded %1 %2 for this credit memo, which Magento totals at %3 %2.',
                number_format($refunded / 100, 2, '.', ''),
                $order->getOrderCurrencyCode(),
                number_format((float) $creditmemo->getGrandTotal(), 2, '.', '')
            );
            $order->addCommentToStatusHistory($text);
            // Magento records its own total as refunded, so the merchant is told what the
            // customer actually got back.
            $this->adminNotice($text);
        }

        return null;
    }

    /**
     * Tells the merchant that a credit memo was only partly refunded at Briqpay.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $captureId the capture whose refund failed
     * @param int $refunded what was refunded from the other captures, minor units
     * @param \Exception $error
     * @return void
     */
    private function reportPartialRefund($order, string $captureId, int $refunded, \Exception $error): void
    {
        $this->logger->critical('Briqpay: credit memo only partly refunded', [
            'orderIncrementId' => $order->getIncrementId(),
            'failedCaptureId' => $captureId,
            'refundedAmount' => $refunded,
        ]);
        $text = (string) __(
            'Briqpay refunded only %1 %2 of this credit memo: the refund from capture %3 failed (%4). '
            . 'Refund the rest in the Briqpay backoffice.',
            number_format($refunded / 100, 2, '.', ''),
            $order->getOrderCurrencyCode(),
            $captureId,
            CaptureOrder::readableError($error)
        );
        $order->addCommentToStatusHistory($text);
        $this->adminNotice($text);
    }

    /**
     * The credit memo's own total and tax, in minor units.
     *
     * @param Creditmemo $creditmemo
     * @param SessionLedger $ledger
     * @return array{incVat:int,vat:int,fractional:bool}
     */
    private function documentTotal(Creditmemo $creditmemo, SessionLedger $ledger): array
    {
        $grandTotal = (float) $creditmemo->getGrandTotal();
        $incVat = MinorUnits::fromFloat($grandTotal);
        $fractional = abs($grandTotal * 100 - round($grandTotal * 100)) >= 0.0001;
        $order = $creditmemo->getOrder();
        if ($fractional && $order !== null && (float) $order->getTotalPaid() > 0) {
            // Magento has booked this credit memo already, rounding its order total to
            // whole minor units: a fractional total (a cart rule that also discounts the
            // shipping) can leave one minor unit at Briqpay that no credit memo of whole
            // minor units matches. Then this credit memo takes everything the captures
            // still hold. Magento may still offer that minor unit for a credit memo of its
            // own; Briqpay then holds nothing for it, and it is refused with that reason.
            $open = MinorUnits::fromFloat((float) $order->getTotalPaid())
                - MinorUnits::fromFloat((float) $order->getTotalRefunded());
            $held = 0;
            foreach ($ledger->captures() as $capture) {
                $held += $ledger->refundable($capture)['incVat'];
            }
            if ($open >= 0 && $open <= 1 && $held - $incVat > 0 && $held - $incVat <= 1) {
                $incVat = $held;
            }
        }

        return [
            'incVat' => $incVat,
            'vat' => MinorUnits::fromFloat((float) $creditmemo->getTaxAmount()),
            // Not a whole number of minor units: Magento's own miscalculation, see
            // RefundCartBuilder::reconcile().
            'fractional' => $fractional,
        ];
    }

    /**
     * Tells the merchant that the refund's VAT differs from Magento's credit memo.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function reportVatDifference($order): void
    {
        $difference = $this->refundCartBuilder->vatDifference();
        if ($difference === 0) {
            return;
        }
        $this->logger->warning('Briqpay: refunded VAT differs from the credit memo', [
            'orderIncrementId' => $order->getIncrementId(),
            'vatDifference' => $difference,
        ]);
        $text = $difference > 0
            ? (string) __(
                'Briqpay refunded %1 %2 more VAT than Magento\'s credit memo books: the refund carries '
                . 'the VAT of what was captured, and every line\'s VAT matches its tax rate. The amount is unchanged.',
                number_format($difference / 100, 2, '.', ''),
                $order->getOrderCurrencyCode()
            )
            : (string) __(
                'Briqpay refunded %1 %2 less VAT than Magento\'s credit memo books: a refund carries no more VAT '
                . 'than its capture still holds, and every line\'s VAT matches its tax rate. The amount is unchanged.',
                number_format(-$difference / 100, 2, '.', ''),
                $order->getOrderCurrencyCode()
            );
        $order->addCommentToStatusHistory($text);
        $this->adminNotice($text);
    }

    /**
     * Tells the merchant when Magento's credit memo total differed from its own lines.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function reportReconciliation($order): void
    {
        $gap = $this->refundCartBuilder->reconciled();
        if ($gap === null) {
            return;
        }
        $text = $gap['incVat'] === 0
            ? (string) __(
                'Briqpay: Magento\'s credit memo VAT differs by %1 %2 from its own lines; Briqpay refunded '
                . 'Magento\'s VAT, adjusted on an existing line.',
                number_format($gap['vat'] / 100, 2, '.', ''),
                $order->getOrderCurrencyCode()
            )
            : (string) __(
                'Briqpay: Magento\'s credit memo total differs by %1 %2 from its own lines; Briqpay refunded the '
                . 'credit memo total, with the difference on a line of its own.',
                number_format($gap['incVat'] / 100, 2, '.', ''),
                $order->getOrderCurrencyCode()
            );
        $this->logger->warning('Briqpay: credit memo total differs from its lines', [
            'orderIncrementId' => $order->getIncrementId(),
            'difference' => $gap['incVat'],
            'vatDifference' => $gap['vat'],
        ]);
        $order->addCommentToStatusHistory($text);
        $this->adminNotice($text);
    }

    /**
     * Tells the merchant when part of the credit memo was refunded from another capture.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function reportMoves($order): void
    {
        foreach ($this->refundCartBuilder->moved() as $move) {
            $text = (string) __(
                'Briqpay: %1 %2 of this credit memo was refunded from capture %3, because Magento splits '
                . 'the discount over credit memos differently from the invoices and capture %4 holds '
                . 'that much less. The refunds together equal the credit memo.',
                number_format($move['amount'] / 100, 2, '.', ''),
                $order->getOrderCurrencyCode(),
                $move['from'],
                $move['to']
            );
            $this->logger->warning('Briqpay: part of a credit memo refunded from another capture', [
                'orderIncrementId' => $order->getIncrementId(),
                'fromCaptureId' => $move['from'],
                'toCaptureId' => $move['to'],
                'amount' => $move['amount'],
            ]);
            $order->addCommentToStatusHistory($text);
            $this->adminNotice($text);
        }
    }

    /**
     * A warning in the admin, when there is an admin request to show it in.
     *
     * @param string $text
     * @return void
     */
    private function adminNotice(string $text): void
    {
        try {
            $messages = $this->messageManager
                ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Framework\Message\ManagerInterface::class);
        } catch (\RuntimeException $e) {
            return;
        }
        $messages->addWarningMessage($text);
    }

    /**
     * Refuses a refund that would not equal its credit memo, when it must (see
     * CaptureOrder::mustMatchDocument()). A credit memo total that is not a whole minor
     * unit is Magento's own miscalculation; that refund goes ahead and is noted on the order.
     *
     * @param SessionLedger $ledger
     * @param array<int, array<string, mixed>> $items
     * @param array<string, array<int, array<string, mixed>>> $carts
     * @param Creditmemo $creditmemo
     * @return void
     * @throws LocalizedException
     */
    private function refuseDocumentMismatch(SessionLedger $ledger, array $items, array $carts, Creditmemo $creditmemo): void
    {
        $lines = [];
        $total = 0;
        foreach ($carts as $cart) {
            foreach ($cart as $line) {
                $lines[] = $line;
                $total += (int) $line['totalAmount'];
            }
        }
        $grandTotal = (float) $creditmemo->getGrandTotal();
        $wholeMinorUnits = abs($grandTotal * 100 - round($grandTotal * 100)) < 0.0001;
        if (!$wholeMinorUnits || !CaptureOrder::mustMatchDocument($ledger, $items, $lines)
            || MinorUnits::fromFloat($grandTotal) === $total
        ) {
            return;
        }
        $shippingInvoice = $this->shippingOnAnotherInvoice($creditmemo);
        if ($shippingInvoice !== null) {
            throw new LocalizedException(__(
                'Shipping on this order was invoiced on invoice #%1. Refund the shipping in a credit memo '
                . 'for that invoice; nothing was refunded.',
                $shippingInvoice
            ));
        }
        $this->logger->error('Briqpay: refund refused, it differs from the credit memo total', [
            'orderIncrementId' => $creditmemo->getOrder()->getIncrementId(),
            'creditmemoTotal' => MinorUnits::fromFloat($grandTotal),
            'refundAmount' => $total,
        ]);
        throw new LocalizedException(__(
            'The Briqpay refund (%1) would not equal the credit memo total (%2), so nothing was refunded. '
            . 'Check the order in the Briqpay backoffice, or refund offline.',
            number_format($total / 100, 2, '.', ''),
            number_format($grandTotal, 2, '.', '')
        ));
    }

    /**
     * The invoice that holds the order's shipping, when this credit memo refunds shipping on
     * another invoice: Magento then takes the shipping tax from the credit memo's own
     * invoice, which has none, and the credit memo cannot be refunded as it stands.
     *
     * @param Creditmemo $creditmemo
     * @return string|null the shipping invoice's number
     */
    private function shippingOnAnotherInvoice(Creditmemo $creditmemo): ?string
    {
        $invoice = $creditmemo->getInvoice();
        if ((float) $creditmemo->getShippingAmount() <= 0 || !$invoice || (float) $invoice->getShippingAmount() > 0) {
            return null;
        }
        foreach ($creditmemo->getOrder()->getInvoiceCollection() as $candidate) {
            if ((float) $candidate->getShippingAmount() > 0) {
                return (string) $candidate->getIncrementId();
            }
        }

        return null;
    }

    /**
     * The shipping this credit memo credits, incl. VAT and after any shipping discount, in minor units.
     *
     * Magento keeps no shipping discount on a credit memo: its discount and tax
     * compensation totals hold the items' amounts plus the shipping share, so the
     * shipping share is the total less the items'.
     *
     * @param Creditmemo $creditmemo
     * @return int
     */
    private static function creditedShipping(Creditmemo $creditmemo): int
    {
        $itemsDiscount = 0;
        $itemsCompensation = 0;
        foreach ($creditmemo->getAllItems() as $item) {
            $itemsDiscount += MinorUnits::fromFloat((float) $item->getDiscountAmount());
            $itemsCompensation += MinorUnits::fromFloat((float) $item->getDiscountTaxCompensationAmount());
        }
        $shippingDiscount = MinorUnits::fromFloat(abs((float) $creditmemo->getDiscountAmount())) - $itemsDiscount;
        if ((float) $creditmemo->getShippingAmount() <= 0 || $shippingDiscount <= 0) {
            return MinorUnits::fromFloat($creditmemo->getShippingInclTax());
        }

        return MinorUnits::fromFloat((float) $creditmemo->getShippingAmount())
            + MinorUnits::fromFloat((float) $creditmemo->getShippingTaxAmount())
            + MinorUnits::fromFloat((float) $creditmemo->getDiscountTaxCompensationAmount()) - $itemsCompensation
            - $shippingDiscount;
    }

    /**
     * Lowers the quantities left on payment_capture_mapping by what was refunded.
     *
     * @param array<int, array<string, mixed>> $cart
     * @param array<string, int|string|null> $orderItemIds
     */
    private function updateCaptureMapping(string $captureId, array $cart, array $orderItemIds): void
    {
        foreach ($cart as $line) {
            $orderItemId = $orderItemIds[(string) $line['reference']] ?? null;
            if ($orderItemId === null || !in_array($line['productType'], CaptureCartBuilder::ITEM_TYPES, true)) {
                continue;
            }
            $left = (float) $line['quantity'];
            $collection = $this->customTableFactory->create()->getCollection();
            $collection->addFieldToFilter('item_id', $orderItemId);
            $collection->addFieldToFilter('capture_id', $captureId);
            foreach ($collection as $row) {
                if ($left <= 0) {
                    break;
                }
                $available = (float) $row->getQuantity();
                $take = min($left, $available);
                if ($take <= 0) {
                    continue;
                }
                $row->setQuantity($available - $take);
                $row->save();
                $left -= $take;
            }
        }
    }
}
