<?php
/**
 * Builds the carts of the Briqpay refunds for one credit memo, one cart per capture: a
 * refund belongs to exactly one capture.
 *
 * Each credited item is taken from the captures that still hold it, oldest first,
 * as a portion of that capture's own line (see LinePortion), with the same share of
 * the item's discount and WEEE lines. Shipping refunded in full echoes the captured
 * shipping line; a partial shipping refund and Magento's adjustment refund / fee go
 * out as adjustment lines. A rounding line of a session created by an earlier module
 * version goes with the refund that empties its capture.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Briqpay\Payments\Model\Utility\Money\VatSplit;
use Magento\Framework\Exception\LocalizedException;

class RefundCartBuilder
{
    /**
     * @var LinePortion
     */
    private LinePortion $linePortion;

    /**
     * @var array<string, array<string, array{quantity: float, totalAmount: int, totalVatAmount: int}>>
     */
    private array $taken = [];

    /**
     * Amounts the last credit memo had to refund from another capture than the one
     * holding its lines: from capture id, to capture id, minor units.
     *
     * @var array<int, array{from: string, to: string, amount: int}>
     */
    private array $moved = [];

    /**
     * The difference the last credit memo's lines had to its own total, incl. VAT and
     * VAT, minor units; null when there was none.
     *
     * @var array{incVat:int,vat:int}|null
     */
    private ?array $reconciled = null;

    /**
     * References of refund lines that take everything left of their captured line.
     *
     * @var array<string, bool>
     */
    private array $closedLines = [];

    /**
     * How much more VAT the closing lines take than Magento's credit memo gives them.
     *
     * @var int
     */
    private int $closedShift = 0;

    /**
     * See vatDifference().
     *
     * @var int
     */
    private int $vatDifference = 0;

    /**
     * @param LinePortion $linePortion
     */
    public function __construct(LinePortion $linePortion)
    {
        $this->linePortion = $linePortion;
    }

    /**
     * The refund carts for one credit memo, keyed by the capture each refunds against.
     *
     * @param SessionLedger $ledger
     * @param array<int,array{reference:string,quantity:float,chargedInclVat?:int}> $items credited quantity
     *        per item reference, and optionally what Magento credits for that line
     * @param int $shippingInclTax shipping credited incl. VAT and after any shipping discount, minor units
     * @param int $adjustment adjustment refund minus adjustment fee, minor units
     * @param bool $shippingCompleted this credit memo completes Magento's shipping refund, so whatever
     *        Briqpay still holds of the shipping line goes back, including a rounding line of an earlier session
     * @param string|null $invoiceCaptureId the capture of the invoice the credit memo was made from;
     *        Magento books the credit memo against that invoice, so its capture is drawn on first
     * @param array{incVat:int,vat:int,fractional?:bool}|null $documentTotal the credit memo's own grand total
     *        and tax, minor units, when the refund must equal it (see reconcile()); fractional when
     *        Magento's total is not a whole number of minor units
     * @return array<string, array<int, array<string, mixed>>> V3 cart lines per capture id
     * @throws LocalizedException when more is credited than Briqpay holds as captured
     */
    public function build(
        SessionLedger $ledger,
        array $items,
        int $shippingInclTax,
        int $adjustment,
        bool $shippingCompleted = false,
        ?string $invoiceCaptureId = null,
        ?array $documentTotal = null
    ): array {
        $this->taken = [];
        $this->moved = [];
        $this->vatDifference = 0;
        $this->reconciled = null;
        $this->closedLines = [];
        $this->closedShift = 0;
        $carts = [];
        if ($invoiceCaptureId !== null && !$this->hasCapture($ledger->captures(), $invoiceCaptureId)) {
            // The invoice's capture was rejected or is unknown: its money was never taken,
            // so nothing of it is refunded, least of all from another invoice's capture.
            throw new LocalizedException(__(
                'The invoice\'s Briqpay capture %1 is not an approved capture of this order; nothing was '
                . 'sent to Briqpay. Check the invoice in the Briqpay backoffice.',
                $invoiceCaptureId
            ));
        }
        $captures = $this->invoiceCaptureFirst($ledger->captures(), $invoiceCaptureId);

        foreach ($items as $item) {
            $left = $item['quantity'];
            foreach ($captures as $capture) {
                if ($left <= LinePortion::QUANTITY_EPSILON) {
                    break;
                }
                $captureId = (string) $capture['captureId'];
                // Magento's charged amount is for the whole credited line, so it only
                // applies when one capture refunds all of it.
                $whole = $left === $item['quantity'];
                $charged = $whole ? ($item['chargedInclVat'] ?? null) : null;
                $document = $whole && $ledger->documentAmounts() ? ($item['document'] ?? null) : null;
                $lines = $this->itemPortions($ledger, $capture, $item['reference'], $left, $charged, $document);
                if ($lines === null) {
                    continue;
                }
                $left -= (float) $lines[0]['quantity'];
                // phpcs:ignore Magento2.Performance.ForeachArrayMerge -- a capture gets a handful of lines per item
                $carts[$captureId] = array_merge($carts[$captureId] ?? [], $lines);
            }
            if ($left > LinePortion::QUANTITY_EPSILON) {
                // What Briqpay holds for the units: the line's total after its discount.
                $line = $ledger->sessionLine($item['reference'], CaptureCartBuilder::ITEM_TYPES);
                $discountLine = $ledger->sessionLine($item['reference'] . '_discount', ['discount']);
                $quantity = (float) ($line['quantity'] ?? 1) ?: 1.0;
                $charged = (int) ($line['totalAmount'] ?? 0) + (int) ($discountLine['totalAmount'] ?? 0);
                $short = MinorUnits::round($charged / $quantity * $left);
                throw new LocalizedException(__(
                    'Briqpay holds less of "%1" captured than this credit memo refunds: %2 units (%3 incl. VAT) '
                    . 'more than it holds; nothing was sent to Briqpay.',
                    $item['reference'],
                    $left,
                    number_format($short / 100, 2, '.', '')
                ));
            }
        }

        if ($shippingInclTax > 0) {
            $this->addShipping($ledger, $carts, $shippingInclTax, $shippingCompleted);
        }
        if ($adjustment < 0) {
            $this->spreadFee($carts, -$adjustment, $invoiceCaptureId);
        } elseif ($adjustment > 0) {
            $this->spreadAdjustment($ledger, $carts, $captures, $adjustment);
        }

        foreach ($ledger->captures() as $capture) {
            $captureId = (string) $capture['captureId'];
            if (!isset($carts[$captureId])) {
                continue;
            }
            $rounding = $this->roundingPortion($ledger, $capture);
            if ($rounding !== null) {
                $carts[$captureId][] = $rounding;
            }
        }
        if ($documentTotal !== null && $ledger->documentAmounts() && $this->allDocumented($items)) {
            $this->reconcile($ledger, $carts, $captures, $documentTotal, count($items));
        }
        $this->keepVatWithinCaptures($ledger, $carts, $captures);
        $this->rebalance($ledger, $carts, $captures);
        if ($ledger->documentAmounts()) {
            foreach ($carts as $captureId => $cart) {
                $fitted = VatSplit::forceFit(VatSplit::fitLines($cart));
                $lost = CaptureOrder::vatTotal($cart) - CaptureOrder::vatTotal($fitted);
                if ($lost > 0) {
                    // A closing adjustment carries the VAT the capture holds, which
                    // Magento's per-line rounding can put a minor unit above the rate's
                    // reach for one line: the same amount over two lines reaches it.
                    $lost -= $this->splitAdjustmentForVat($fitted, $lost, self::loweredRates($cart, $fitted));
                }
                if ($lost > 0 && !$this->withinCapture($fitted, $captureId, $ledger, $captures)) {
                    // Lowered VAT the lines cannot carry would put the refund above the
                    // capture excl. VAT; the lines as Magento has them stay within it.
                    $fitted = $cart;
                    $lost = 0;
                }
                $this->vatDifference -= $lost;
                $carts[$captureId] = $fitted;
            }
            foreach (array_keys($carts) as $captureId) {
                $this->fitRemainder($ledger, (string) $captureId, $carts[$captureId], $captures);
            }
        }

        return $carts;
    }

    /**
     * Leaves no VAT behind on a captured line this refund empties.
     *
     * Magento's per-line VAT (its split over invoices and credit memos, its rounding of the
     * credit memo's tax) can refund a line's whole amount with a minor unit less or more
     * VAT than the capture still holds for it. That minor unit would stay on the capture
     * with no amount to carry it, and a later credit memo for the rest of the capture
     * would be refused for it. It goes on this refund's line for the same product (or,
     * where that line cannot carry it, another of its lines at that rate); amounts are
     * unchanged, and the difference to Magento's VAT is noted as any VAT difference is.
     * A rest of several lines, each with its own rounded VAT, is left as it is.
     *
     * @param SessionLedger $ledger
     * @param string $captureId
     * @param array<int,array<string,mixed>> $cart this refund's lines from the capture
     * @param array<int,array<string,mixed>> $captures
     * @return void
     */
    private function fitRemainder(SessionLedger $ledger, string $captureId, array &$cart, array $captures): void
    {
        $capture = null;
        foreach ($ledger->captures() as $candidate) {
            if ((string) $candidate['captureId'] === $captureId) {
                $capture = $candidate;
            }
        }
        if ($capture === null) {
            return;
        }
        $types = ['physical', 'digital', 'discount', 'shipping_fee', 'surcharge'];
        $references = [];
        foreach ((array) ($capture['cart'] ?? []) as $line) {
            if (in_array($line['productType'] ?? null, $types, true)) {
                $references[(string) ($line['reference'] ?? '')] = (string) $line['productType'];
            }
        }
        foreach ($references as $reference => $type) {
            $captured = $ledger->captureLine($capture, $reference, [$type]);
            if ($captured === null) {
                continue;
            }
            $earlier = $ledger->refunded($captureId, $reference, [$type]);
            $amountLeft = (int) $captured['totalAmount'] - (int) $earlier['totalAmount'];
            $vatLeft = (int) $captured['totalVatAmount'] - (int) $earlier['totalVatAmount'];
            $own = null;
            foreach ($cart as $index => $line) {
                if ((string) ($line['reference'] ?? '') === $reference && $line['productType'] === $type) {
                    $amountLeft -= (int) $line['totalAmount'];
                    $vatLeft -= (int) $line['totalVatAmount'];
                    $own = $index;
                }
            }
            if ($own === null || $amountLeft !== 0 || $vatLeft === 0) {
                continue;
            }
            $rate = (int) ($cart[$own]['taxRate'] ?? 0);
            $index = VatSplit::fits(
                (int) $cart[$own]['totalAmount'],
                (int) $cart[$own]['totalVatAmount'] + $vatLeft,
                $rate
            ) ? $own : $this->lineTakingVatAt($cart, $vatLeft, $rate);
            if ($index === null) {
                continue;
            }
            // Less VAT is more excl. VAT: never more than the capture holds.
            $moved = $cart;
            $this->addVat($moved, $index, $vatLeft);
            if (!$this->withinCapture($moved, $captureId, $ledger, $captures)) {
                continue;
            }
            $cart = $moved;
            $this->vatDifference += $vatLeft;
        }
    }

    /**
     * The first line at $rate, with an amount, whose VAT still fits it with $vat more.
     *
     * Item lines first, then shipping, fees and discounts.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param int $vat
     * @param int $rate
     * @return int|null
     */
    private function lineTakingVatAt(array $cart, int $vat, int $rate): ?int
    {
        foreach (['physical', 'digital', 'shipping_fee', 'surcharge', 'discount'] as $type) {
            foreach ($cart as $index => $line) {
                if ($line['productType'] === $type && (int) ($line['taxRate'] ?? 0) === $rate
                    && (int) $line['totalAmount'] !== 0
                    && VatSplit::fits((int) $line['totalAmount'], (int) $line['totalVatAmount'] + $vat, $rate)
                ) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Whether every credited item came with its credit memo line's own amounts.
     *
     * @param array<int,array<string,mixed>> $items
     * @return bool
     */
    private function allDocumented(array $items): bool
    {
        foreach ($items as $item) {
            if (!isset($item['document'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The difference the last build() settled between the lines and the credit memo total.
     *
     * @return array{incVat:int,vat:int}|null
     */
    public function reconciled(): ?array
    {
        return $this->reconciled;
    }

    /**
     * Makes the refund equal the credit memo's own total.
     *
     * Magento totals a credit memo in its totals collectors, which can round tax and
     * discount tax compensation from the invoice where the credit memo's item fields
     * hold the order's split, so its total can differ from its own lines by up to two
     * minor units per line. The lines keep Magento's item amounts; the difference, incl. VAT and VAT,
     * goes on one labelled line, so the refund equals what Magento records as refunded.
     * A larger difference is not rounding; it is left for the caller to refuse.
     *
     * @param SessionLedger $ledger
     * @param array<string,array<int,array<string,mixed>>> $carts
     * @param array<int,array<string,mixed>> $captures invoice capture first
     * @param array{incVat:int,vat:int,fractional?:bool} $documentTotal
     * @param int $creditedLines
     * @return void
     */
    private function reconcile(
        SessionLedger $ledger,
        array &$carts,
        array $captures,
        array $documentTotal,
        int $creditedLines
    ): void {
        $total = 0;
        $vat = 0;
        foreach ($carts as $cart) {
            foreach ($cart as $line) {
                $total += (int) $line['totalAmount'];
                $vat += (int) $line['totalVatAmount'];
            }
        }
        $gap = $documentTotal['incVat'] - $total;
        $gapVat = $documentTotal['vat'] - ($vat - $this->closedShift);
        // Tax and discount tax compensation are each rounded on their own, so a line can be
        // two minor units off (7.70 + 1.31 on the line, 7.69 + 1.30 in the total).
        $limit = 2 * max(1, $creditedLines);
        // A total that is not a whole number of minor units is Magento's own
        // miscalculation (its credit memo discount when a cart rule also discounts the
        // shipping): the difference can be larger, and Magento's total still rules.
        if (!empty($documentTotal['fractional'])) {
            $limit = PHP_INT_MAX;
        }
        if (($gap === 0 && $gapVat === 0) || abs($gap) > $limit) {
            return;
        }
        if (abs($gapVat) > 2 * max(1, $creditedLines)) {
            // Magento books an adjustment without VAT where Briqpay must carry it; the
            // amount is still bridged and the VAT difference reported.
            $this->vatDifference -= $gapVat;
            $gapVat = 0;
        }
        // The capture the difference comes from: the credit memo's own, when it has that
        // much left excl. VAT (the difference carries no VAT); else another capture of the
        // order that has, as when a credit memo of items only is totalled above its lines
        // and the shipping's capture holds the rest. A difference the other way (Magento
        // lower) only ever goes on a capture this credit memo refunds from.
        // The difference carries the VAT of what it is taken from, in proportion, as an
        // adjustment does: a part without VAT would use up the capture's amount excl.
        // VAT and leave VAT nobody could refund later.
        $captureId = null;
        $exLeft = 0;
        $bridge = [];
        $bridges = [];
        $withCart = [];
        $others = [];
        foreach ($captures as $candidate) {
            if (isset($carts[(string) $candidate['captureId']])) {
                $withCart[] = $candidate;
            } else {
                $others[] = $candidate;
            }
        }
        $candidates = array_merge($withCart, $gap > 0 ? $others : []);
        foreach ($candidates as $candidate) {
            $id = (string) $candidate['captureId'];
            $room = $this->room($ledger, $candidate, $carts[$id] ?? []);
            $lines = $gap === 0 ? [] : $this->bridgeLines($gap, $candidate, $ledger, $carts[$id] ?? []);
            $totals = CaptureOrder::orderTotals($lines);
            if ($room['incVat'] >= $totals['amountIncVat'] && $room['exVat'] >= $totals['amountExVat']) {
                $captureId = $id;
                $exLeft = $room['exVat'] - $totals['amountExVat'];
                $bridge = $lines;
                break;
            }
        }
        if ($captureId === null && $gap > 0) {
            // No single capture holds the difference: a minor unit here and there, as
            // when every capture was refunded at Magento's rounded totals. Each capture
            // with room gives what it has, until the difference is covered.
            $remaining = $gap;
            foreach ($candidates as $candidate) {
                $id = (string) $candidate['captureId'];
                $room = $this->room($ledger, $candidate, $carts[$id] ?? []);
                $take = min($remaining, $room['incVat']);
                while ($take > 0) {
                    $lines = $this->bridgeLines($take, $candidate, $ledger, $carts[$id] ?? []);
                    if (CaptureOrder::orderTotals($lines)['amountExVat'] <= $room['exVat']) {
                        break;
                    }
                    $take--;
                }
                if ($take <= 0) {
                    continue;
                }
                $bridges[$id] = $lines;
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }
            if ($remaining !== 0) {
                return;
            }
            foreach ($bridges as $id => $lines) {
                $carts[$id] = $carts[$id] ?? [];
                foreach ($lines as $line) {
                    $carts[$id][] = $line;
                    $this->vatDifference += (int) $line['totalVatAmount'];
                }
            }
            $this->reconciled = ['incVat' => $gap, 'vat' => 0];
            if ($gapVat !== 0) {
                $this->vatDifference -= $gapVat;
            }

            return;
        }
        if ($captureId === null) {
            // No capture can give the difference; the lines are refunded as they are and
            // the order notes Magento's total.
            return;
        }
        $carts[$captureId] = $carts[$captureId] ?? [];
        // Less VAT means more excl. VAT. Briqpay refunds no more excl. VAT than a capture
        // holds, so a VAT difference is only taken when the capture can give it; otherwise
        // the refund keeps the captured VAT and the note records Magento's.
        if (-$gapVat > $exLeft) {
            $this->vatDifference -= $gapVat;
            $gapVat = 0;
        }
        if ($gap === 0 && $gapVat === 0) {
            return;
        }
        // The VAT difference goes on the VAT of lines already in the refund, a discount
        // line first, their amounts unchanged, as far as each line's VAT still fits its
        // rate: a minor unit of VAT on a line of a minor unit or two would contradict any
        // rate. What no line can take is not refunded, and noted.
        if ($gapVat !== 0) {
            $placed = $this->moveVat($carts[$captureId], $gapVat);
            $this->vatDifference -= $gapVat - $placed;
            $gapVat = $placed;
        }
        $this->reconciled = ['incVat' => $gap, 'vat' => $gapVat];
        foreach ($bridge as $line) {
            $carts[$captureId][] = $line;
            // Magento's total carries no VAT for this difference; Briqpay's does.
            $this->vatDifference += (int) $line['totalVatAmount'];
        }
    }

    /**
     * The lines settling a difference to Magento's credit memo total on a capture: split
     * over the VAT rates of what the capture has left (a positive difference) or of the
     * lines being refunded from it (a negative one); without VAT when there is nothing
     * to split over.
     *
     * @param int $gap minor units, with sign
     * @param array<string,mixed> $capture
     * @param SessionLedger $ledger
     * @param array<int,array<string,mixed>> $cart
     * @return array<int,array<string,mixed>>
     */
    private function bridgeLines(int $gap, array $capture, SessionLedger $ledger, array $cart): array
    {
        $byRate = $gap > 0
            ? array_filter($ledger->refundableByRate($capture, $cart), static function (array $rest): bool {
                return $rest['incVat'] > 0;
            })
            : $this->rateShares($cart);
        if ($byRate === []) {
            return [
                self::adjustmentLine('credit_memo_total', 'Difference to the Magento credit memo total', $gap, 0, 0),
            ];
        }
        $lines = [];
        foreach ($this->splitByRate($gap, $byRate) as $rate => $piece) {
            $lines[] = self::adjustmentLine(
                'credit_memo_total',
                'Difference to the Magento credit memo total',
                $piece['incVat'],
                $piece['vat'],
                (int) $rate
            );
        }

        return $lines;
    }

    /**
     * Adds a VAT difference to the VAT of the refund's lines, a minor unit at a time.
     *
     * Each minor unit goes to a discount line first, else an item line, whose VAT still
     * fits its rate after.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param int $vat
     * @return int how much of it the lines took
     */
    private function moveVat(array &$cart, int $vat): int
    {
        $step = $vat > 0 ? 1 : -1;
        $placed = 0;
        while ($placed !== $vat) {
            $index = $this->lineTakingVat($cart, $step);
            if ($index === null) {
                break;
            }
            $this->addVat($cart, $index, $step);
            $placed += $step;
        }

        return $placed;
    }

    /**
     * The first discount, item, fee or adjustment line whose VAT still fits its rate with $vat more.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param int $vat
     * @return int|null
     */
    private function lineTakingVat(array $cart, int $vat): ?int
    {
        foreach (['discount', 'physical', 'digital', 'surcharge', 'shipping_fee', 'adjustment'] as $type) {
            foreach ($cart as $index => $line) {
                // A line that refunds everything left of its captured line carries exactly
                // the VAT that line still holds; moving VAT off it would strand a cent.
                if (isset($this->closedLines[(string) ($line['reference'] ?? '')])) {
                    continue;
                }
                if ($line['productType'] === $type && \Briqpay\Payments\Model\Utility\Money\VatSplit::fits(
                    (int) $line['totalAmount'],
                    (int) $line['totalVatAmount'] + $vat,
                    (int) ($line['taxRate'] ?? 0)
                )) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Adds VAT to one line, keeping its amount; a discount line's unit price follows.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param int $index
     * @param int $vat
     * @return void
     */
    private function addVat(array &$cart, int $index, int $vat): void
    {
        $line = $cart[$index];
        $cart[$index]['totalVatAmount'] = (int) $line['totalVatAmount'] + $vat;
        if (in_array($line['productType'], ['discount', 'surcharge', 'adjustment'], true)) {
            // Single-unit lines whose unit price is their amount excl. VAT.
            $cart[$index]['unitPrice'] = (int) $line['totalAmount'] - (int) $line['totalVatAmount'] - $vat;
        }
    }

    /**
     * How much more VAT the last build() refunds than Magento's credit memo.
     *
     * In minor units; non-zero only where Magento's VAT fits no split of the lines' rates.
     *
     * @return int
     */
    public function vatDifference(): int
    {
        return $this->vatDifference;
    }

    /**
     * What the last build() had to refund from another capture (see rebalance()).
     *
     * @return array<int, array{from: string, to: string, amount: int}>
     */
    public function moved(): array
    {
        return $this->moved;
    }

    /**
     * Keeps each refund within the VAT its capture still holds.
     *
     * It also takes all of that VAT when the refund takes all of the capture's amount.
     * Magento's credit memo VAT can be a minor unit above what a capture has left after an
     * earlier refund took VAT in proportion, or below it when the credit memo books the
     * discount VAT as the order does and the capture as its invoice did. The capture would
     * then be left with VAT below zero, or VAT with no amount, that no later refund could
     * settle, and a refund takes no more excl. VAT than its capture holds. The VAT moves
     * on the lines already in the refund, their amounts unchanged, as far as each line's
     * VAT still fits its rate, and the difference to Magento's VAT is noted.
     *
     * @param SessionLedger $ledger
     * @param array<string,array<int,array<string,mixed>>> $carts
     * @param array<int,array<string,mixed>> $captures
     * @return void
     */
    private function keepVatWithinCaptures(SessionLedger $ledger, array &$carts, array $captures): void
    {
        foreach ($captures as $capture) {
            $captureId = (string) $capture['captureId'];
            if (!isset($carts[$captureId])) {
                continue;
            }
            $left = $ledger->refundable($capture);
            $totals = CaptureOrder::orderTotals($carts[$captureId]);
            $vatAfter = ($left['incVat'] - $left['exVat']) - ($totals['amountIncVat'] - $totals['amountExVat']);
            $amountAfter = $left['incVat'] - $totals['amountIncVat'];
            if ($vatAfter < 0 || ($amountAfter <= 0 && $vatAfter > 0)) {
                $this->vatDifference += $this->moveVat($carts[$captureId], $vatAfter);
            }
        }
    }

    /**
     * Places $vat more minor units of VAT on the adjustment lines by splitting the
     * largest one in two, so the VAT can be placed while each line stays consistent with
     * its rate. Nothing is added to the refund; the same amount is spread over one line
     * more per unit.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param int $vat
     * @param array<int,int> $rates the rates to split first (those whose VAT was lowered)
     * @return int how much was placed
     */
    private function splitAdjustmentForVat(array &$cart, int $vat, array $rates = []): int
    {
        $placed = 0;
        while ($placed < $vat) {
            $largest = self::largestTaxedAdjustment($cart, $rates) ?? self::largestTaxedAdjustment($cart);
            $halves = $largest === null ? null : self::splitForVat($cart[$largest], 1);
            if ($halves === null) {
                break;
            }
            array_splice($cart, $largest, 1, $halves);
            $placed++;
        }

        return $placed;
    }

    /**
     * Whether refunding the pending lines (a line and its discount) would leave the
     * capture holding, at the line's rate, VAT its remaining amount cannot carry (a
     * minor unit more than the rate allows, or VAT without an amount).
     *
     * @param SessionLedger $ledger
     * @param array<string,mixed> $capture
     * @param array<int,array<string,mixed>> $pending
     * @param int $rate
     * @return bool
     */
    private function strandsVat(SessionLedger $ledger, array $capture, array $pending, int $rate): bool
    {
        $left = $ledger->refundableByRate($capture, $pending)[$rate] ?? ['incVat' => 0, 'vat' => 0];

        return !VatSplit::fits($left['incVat'], $left['vat'], $rate);
    }

    /**
     * Index of the largest adjustment line that can be split for VAT.
     *
     * One with a VAT rate (one of $rates when given) and enough amount for two lines of
     * more than a minor unit each.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param array<int,int> $rates
     * @return int|null
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- private pure helper, never intercepted
    private static function largestTaxedAdjustment(array $cart, array $rates = []): ?int
    {
        $largest = null;
        foreach ($cart as $index => $line) {
            $rate = (int) ($line['taxRate'] ?? 0);
            if ($line['productType'] === 'adjustment' && $rate > 0 && (int) $line['totalAmount'] >= 4
                && ($rates === [] || in_array($rate, $rates, true))
                && ($largest === null || (int) $line['totalAmount'] > (int) $cart[$largest]['totalAmount'])
            ) {
                $largest = $index;
            }
        }

        return $largest;
    }

    /**
     * The VAT rates of the lines whose VAT the fit pass lowered.
     *
     * @param array<int,array<string,mixed>> $before
     * @param array<int,array<string,mixed>> $after
     * @return array<int,int>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- private pure helper, never intercepted
    private static function loweredRates(array $before, array $after): array
    {
        $rates = [];
        foreach ($after as $index => $line) {
            if ((int) $line['totalVatAmount'] < (int) ($before[$index]['totalVatAmount'] ?? 0)) {
                $rates[] = (int) ($line['taxRate'] ?? 0);
            }
        }

        return array_values(array_unique($rates));
    }

    /**
     * Whether the cart stays within what its capture has left, incl. and excl. VAT.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param string $captureId
     * @param SessionLedger $ledger
     * @param array<int,array<string,mixed>> $captures
     * @return bool
     */
    private function withinCapture(array $cart, string $captureId, SessionLedger $ledger, array $captures): bool
    {
        foreach ($captures as $capture) {
            if ((string) $capture['captureId'] === $captureId) {
                $left = $ledger->refundable($capture);
                $totals = CaptureOrder::orderTotals($cart);

                return $totals['amountIncVat'] <= $left['incVat'] && $totals['amountExVat'] <= $left['exVat'];
            }
        }

        return true;
    }

    /**
     * The line as two lines of the same kind.
     *
     * Together they hold its amount and $more VAT, each within a minor unit of its
     * rate; null when no split does.
     *
     * @param array<string,mixed> $line
     * @param int $more
     * @return array<int,array<string,mixed>>|null
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- private pure helper, never intercepted
    private static function splitForVat(array $line, int $more): ?array
    {
        $total = (int) $line['totalAmount'];
        $vat = (int) $line['totalVatAmount'] + $more;
        $rate = (int) $line['taxRate'];
        // The fraction of a line's exact VAT moves by the rate per minor unit, so a split
        // that fits, if any, is within a few steps of the middle.
        // Neither half is a minor unit or two: no line that is only its own VAT.
        $middle = intdiv($total, 2);
        for ($first = $middle; $first >= 2 && $first > $middle - 100; $first--) {
            $firstVat = (int) floor($first * $rate / (10000 + $rate)) + 1;
            $secondVat = $vat - $firstVat;
            if (VatSplit::fits($first, $firstVat, $rate) && VatSplit::fits($total - $first, $secondVat, $rate)) {
                return [
                    self::adjustmentLine($line['reference'], $line['name'], $first, $firstVat, $rate),
                    self::adjustmentLine($line['reference'], $line['name'], $total - $first, $secondVat, $rate),
                ];
            }
        }

        return null;
    }

    /**
     * Settles the minor units of Magento's discount split across captures.
     *
     * Magento splits a line's discount over credit memos and over invoices differently,
     * so a credit memo for one invoice's units can come to a minor unit or two more than
     * that invoice's capture has left while another capture holds them. That part is
     * refunded from the other capture, on labelled lines, so the refunds together equal
     * the credit memo; the order notes it. A larger difference is refused.
     *
     * @param SessionLedger $ledger
     * @param array<string,array<int,array<string,mixed>>> $carts
     * @param array<int,array<string,mixed>> $captures invoice capture first
     * @return void
     * @throws LocalizedException when no other capture holds the excess
     */
    private function rebalance(SessionLedger $ledger, array &$carts, array $captures): void
    {
        foreach ($captures as $capture) {
            $captureId = (string) $capture['captureId'];
            if (!isset($carts[$captureId])) {
                continue;
            }
            $totals = CaptureOrder::orderTotals($carts[$captureId]);
            $left = $ledger->refundable($capture);
            $excess = max($totals['amountIncVat'] - $left['incVat'], $totals['amountExVat'] - $left['exVat'], 0);
            if ($excess === 0 || $excess > count($carts[$captureId])) {
                continue;
            }
            $remaining = $excess;
            foreach ($captures as $donor) {
                $donorId = (string) $donor['captureId'];
                if ($donorId === $captureId) {
                    continue;
                }
                $donorTotals = CaptureOrder::orderTotals($carts[$donorId] ?? []);
                $donorLeft = $ledger->refundable($donor);
                $room = min(
                    $donorLeft['incVat'] - $donorTotals['amountIncVat'],
                    $donorLeft['exVat'] - $donorTotals['amountExVat']
                );
                $take = min($room, $remaining);
                if ($take <= 0) {
                    continue;
                }
                $carts[$captureId][] = self::adjustmentLine(
                    'credit_memo_part',
                    'Credit memo part refunded from capture ' . $donorId,
                    -$take,
                    0,
                    0
                );
                $carts[$donorId][] = self::adjustmentLine(
                    'credit_memo_part',
                    'Credit memo part held by this capture for capture ' . $captureId,
                    $take,
                    0,
                    0
                );
                $this->moved[] = ['from' => $donorId, 'to' => $captureId, 'amount' => $take];
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }
            if ($remaining > 0) {
                throw new LocalizedException(__(
                    'Briqpay holds %1 less captured than this credit memo refunds; nothing was sent to Briqpay.',
                    number_format($remaining / 100, 2, '.', '')
                ));
            }
        }
    }

    /**
     * Takes an adjustment fee off the lines this credit memo refunds, the invoice's own
     * capture first, each capture's refund down to zero at most: a refund below zero on
     * a capture that holds none of the credited lines is refused by Briqpay, after the
     * other captures were already refunded. The fee reduces the VAT in proportion, as
     * the lines carry it. Magento never totals a credit memo below zero, so the lines
     * always cover the fee.
     *
     * @param array<string,array<int,array<string,mixed>>> $carts
     * @param int $fee minor units, positive
     * @param string|null $invoiceCaptureId
     * @return void
     * @throws LocalizedException when the credited lines do not cover the fee
     */
    private function spreadFee(array &$carts, int $fee, ?string $invoiceCaptureId): void
    {
        $order = array_map('strval', array_keys($carts));
        if ($invoiceCaptureId !== null && in_array($invoiceCaptureId, $order, true)) {
            $order = array_merge([$invoiceCaptureId], array_diff($order, [$invoiceCaptureId]));
        }
        $left = $fee;
        foreach ($order as $captureId) {
            $totals = CaptureOrder::orderTotals($carts[$captureId]);
            if ($totals['amountIncVat'] <= 0) {
                continue;
            }
            $take = min($left, $totals['amountIncVat']);
            $shares = $this->rateShares($carts[$captureId]);
            $lines = $this->feeLines($take, $shares);
            // The split rounds per rate; a minor unit less keeps the refund at or above zero excl. VAT.
            while ($take > 0 && $totals['amountExVat'] + CaptureOrder::orderTotals($lines)['amountExVat'] < 0) {
                $take--;
                $lines = $this->feeLines($take, $shares);
            }
            if ($take <= 0) {
                continue;
            }
            foreach ($lines as $line) {
                $carts[$captureId][] = $line;
            }
            $left -= $take;
            if ($left === 0) {
                return;
            }
        }
        throw new LocalizedException(__(
            'The adjustment fee is %1 more than the lines this credit memo refunds; nothing was sent to Briqpay.',
            number_format($left / 100, 2, '.', '')
        ));
    }

    /**
     * The fee as negative adjustment lines, split over the VAT rates of the refunded lines.
     *
     * @param int $fee minor units, positive
     * @param array<int,array{incVat:int,vat:int}> $shares
     * @return array<int, array<string, mixed>>
     */
    private function feeLines(int $fee, array $shares): array
    {
        if ($fee <= 0) {
            return [];
        }
        $pieces = $shares === [] ? [0 => ['incVat' => -$fee, 'vat' => 0]] : $this->splitByRate(-$fee, $shares);
        $lines = [];
        foreach ($pieces as $rate => $piece) {
            $lines[] = self::adjustmentLine('adjustment', 'Adjustment', $piece['incVat'], $piece['vat'], (int) $rate);
        }

        return $lines;
    }

    /**
     * Puts an adjustment refund on the captures that still hold money, the credit
     * memo's own invoice capture first: Briqpay refunds no more from a capture than it
     * has left, incl. and excl. VAT, so a larger adjustment is spread over several, and
     * on each it is split over the VAT rates the capture holds (see splitByRate()).
     *
     * @param SessionLedger $ledger
     * @param array<string,array<int,array<string,mixed>>> $carts
     * @param array<int,array<string,mixed>> $captures invoice capture first
     * @param int $adjustment
     * @return void
     * @throws LocalizedException when the captures together hold less
     */
    private function spreadAdjustment(SessionLedger $ledger, array &$carts, array $captures, int $adjustment): void
    {
        // With the VAT of what each capture has left, in proportion. Briqpay refunds no
        // more excl. VAT than a capture holds, so a part without VAT (as Magento books an
        // adjustment) would use up the capture's amount excl. VAT and leave an amount
        // incl. VAT that could never be refunded.
        $left = $adjustment;
        foreach ($captures as $capture) {
            $captureId = (string) $capture['captureId'];
            $cart = $carts[$captureId] ?? [];
            $room = $this->room($ledger, $capture, $cart);
            $allRests = array_filter(
                $ledger->refundableByRate($capture, $cart),
                static function (array $rest): bool {
                    return $rest['incVat'] !== 0 || $rest['vat'] !== 0;
                }
            );
            $byRate = array_filter($allRests, static function (array $rest): bool {
                return $rest['incVat'] > 0;
            });
            if ($room['incVat'] <= 0 || $byRate === []) {
                continue;
            }
            $take = min($left, $room['incVat']);
            // Closing takes every rest, a negative one (a minor unit given to another
            // capture as a credit_memo_part at 0 %) included, so nothing stays.
            $lines = $take === $room['incVat']
                ? $this->closingLines($allRests)
                : $this->adjustmentLines(0, $take, $byRate);
            // The split rounds per rate; a minor unit less keeps it within what is left excl. VAT.
            while ($take > 0 && CaptureOrder::orderTotals($lines)['amountExVat'] > $room['exVat']) {
                $take--;
                $lines = $this->adjustmentLines(0, $take, $byRate);
            }
            if ($take <= 0) {
                continue;
            }
            foreach ($lines as $line) {
                $carts[$captureId][] = $line;
            }
            $left -= $take;
            if ($left === 0) {
                return;
            }
        }
        throw new LocalizedException(__(
            'The adjustment refund is %1 more than Briqpay still holds captured for this order; '
            . 'nothing was sent to Briqpay.',
            number_format($left / 100, 2, '.', '')
        ));
    }

    /**
     * Adjustment lines taking exactly what a capture has left, per rate, so nothing stays.
     *
     * @param array<int,array{incVat:int,vat:int}> $byRate
     * @return array<int, array<string, mixed>>
     */
    private function closingLines(array $byRate): array
    {
        $lines = [];
        foreach ($byRate as $rate => $rest) {
            $lines[] = self::adjustmentLine('adjustment', 'Adjustment', $rest['incVat'], $rest['vat'], (int) $rate);
        }

        return $lines;
    }

    /**
     * What a capture can still give, incl. and excl. VAT, besides the lines already on it.
     *
     * @param SessionLedger $ledger
     * @param array<string,mixed> $capture
     * @param array<int,array<string,mixed>> $cart
     * @return array{incVat:int,exVat:int}
     */
    private function room(SessionLedger $ledger, array $capture, array $cart): array
    {
        $left = $ledger->refundable($capture);
        $taken = CaptureOrder::orderTotals($cart);

        return [
            'incVat' => $left['incVat'] - $taken['amountIncVat'],
            'exVat' => $left['exVat'] - $taken['amountExVat'],
        ];
    }

    /**
     * An adjustment as a part without VAT and a part with the VAT the capture holds.
     *
     * @param int $plain incl. VAT, minor units, at 0 %
     * @param int $withVat incl. VAT, minor units, split over the capture's VAT rates
     * @param array<int,array{incVat:int,vat:int}> $byRate
     * @return array<int, array<string, mixed>>
     */
    private function adjustmentLines(int $plain, int $withVat, array $byRate): array
    {
        $lines = [];
        if ($plain > 0) {
            $lines[] = self::adjustmentLine('adjustment', 'Adjustment', $plain, 0, 0);
        }
        if ($withVat > 0) {
            foreach ($this->splitByRate($withVat, $byRate) as $rate => $piece) {
                $lines[] = self::adjustmentLine('adjustment', 'Adjustment', $piece['incVat'], $piece['vat'], $rate);
            }
        }

        return $lines;
    }

    /**
     * What a cart holds per VAT rate, incl. VAT and VAT, for rates with an amount above zero.
     *
     * @param array<int,array<string,mixed>> $cart
     * @return array<int,array{incVat:int,vat:int}>
     */
    private function rateShares(array $cart): array
    {
        $shares = [];
        foreach ($cart as $line) {
            $rate = (int) ($line['taxRate'] ?? 0);
            $shares[$rate] = $shares[$rate] ?? ['incVat' => 0, 'vat' => 0];
            $shares[$rate]['incVat'] += (int) ($line['totalAmount'] ?? 0);
            $shares[$rate]['vat'] += (int) ($line['totalVatAmount'] ?? 0);
        }

        return array_filter($shares, static function (array $share): bool {
            return $share['incVat'] > 0;
        });
    }

    /**
     * An amount split over VAT rates in proportion to what each still holds.
     *
     * Each part takes the VAT share its rate holds, so a refund uses up a capture incl. and
     * excl. VAT together.
     *
     * @param int $amount incl. VAT, minor units
     * @param array<int,array{incVat:int,vat:int}> $byRate what is left per rate, all positive
     * @return array<int,array{incVat:int,vat:int}> per rate
     */
    private function splitByRate(int $amount, array $byRate): array
    {
        $total = array_sum(array_column($byRate, 'incVat'));
        $parts = [];
        $given = 0;
        $rates = array_keys($byRate);
        foreach ($rates as $index => $rate) {
            $part = $index === count($rates) - 1
                ? $amount - $given
                : MinorUnits::divRound($amount * $byRate[$rate]['incVat'], $total);
            $given += $part;
            if ($part === 0) {
                continue;
            }
            $parts[$rate] = [
                'incVat' => $part,
                'vat' => MinorUnits::divRound($part * $byRate[$rate]['vat'], $byRate[$rate]['incVat']),
            ];
        }

        return $parts;
    }

    /**
     * The captures with the credit memo's own invoice capture moved to the front.
     *
     * @param array<int,array<string,mixed>> $captures
     * @param string|null $invoiceCaptureId
     * @return array<int, array<string, mixed>>
     */
    private function invoiceCaptureFirst(array $captures, ?string $invoiceCaptureId): array
    {
        if ($invoiceCaptureId === null) {
            return $captures;
        }
        $first = [];
        $rest = [];
        foreach ($captures as $capture) {
            if ((string) $capture['captureId'] === $invoiceCaptureId) {
                $first[] = $capture;
            } else {
                $rest[] = $capture;
            }
        }

        return array_merge($first, $rest);
    }

    /**
     * Whether a capture with this id is among the given captures.
     *
     * @param array<int,array<string,mixed>> $captures
     * @param string $captureId
     * @return bool
     */
    private function hasCapture(array $captures, string $captureId): bool
    {
        foreach ($captures as $capture) {
            if ((string) $capture['captureId'] === $captureId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The item's portion from one capture, with its discount and WEEE shares.
     *
     * The item line comes first. Null when the capture holds nothing of the item.
     *
     * @param SessionLedger $ledger
     * @param array<string,mixed> $capture
     * @param string $reference
     * @param float $wanted
     * @param int|null $chargedInclVat what Magento credits for the whole line, incl. VAT and after discount
     * @param array<string,int>|null $document the credit memo line's own amounts (CaptureOrder::documentAmounts())
     * @return array<int, array<string, mixed>>|null
     */
    private function itemPortions(
        SessionLedger $ledger,
        array $capture,
        string $reference,
        float $wanted,
        ?int $chargedInclVat = null,
        ?array $document = null
    ): ?array {
        $captureId = (string) $capture['captureId'];
        $line = $ledger->captureLine($capture, $reference, CaptureCartBuilder::ITEM_TYPES);
        if ($line === null) {
            return null;
        }
        $refunded = $this->taken($ledger, $captureId, $reference, CaptureCartBuilder::ITEM_TYPES);
        $available = (float) $line['quantity'] - $refunded['quantity'];
        if ($available <= LinePortion::QUANTITY_EPSILON) {
            return null;
        }

        $quantity = min($wanted, $available);
        $completes = $this->linePortion->completes((float) $line['quantity'], $quantity, $refunded);
        // The credit memo line's own amounts when this capture refunds all of it; a WEEE
        // line is split by proportion, so such an item is too.
        $document = $document !== null && $quantity >= $wanted
            && $ledger->captureLine($capture, $reference . '_weee_tax', ['surcharge']) === null
            ? $document
            : null;
        if ($document !== null) {
            return $this->documentPortions($ledger, $capture, $line, $reference, $quantity, $document);
        }
        $portion = $this->linePortion->quantityPortion($line, $quantity, $refunded);
        $this->record($captureId, $reference, $portion);
        $lines = [$portion];

        foreach (['_discount' => ['discount'], '_weee_tax' => ['surcharge']] as $suffix => $types) {
            $amountLine = $ledger->captureLine($capture, $reference . $suffix, $types);
            if ($amountLine === null) {
                continue;
            }
            $amountTaken = $this->taken($ledger, $captureId, $reference . $suffix, $types);
            $amountPortion = $this->linePortion->amountPortion(
                $amountLine,
                $quantity,
                (float) $line['quantity'],
                $completes,
                $amountTaken
            );
            if ($suffix === '_discount' && !$completes && $chargedInclVat !== null
                && $quantity >= $wanted
                && $ledger->captureLine($capture, $reference . '_weee_tax', ['surcharge']) === null
            ) {
                $amountPortion = $this->linePortion->amountPortionTowards(
                    $amountPortion,
                    $amountLine,
                    $chargedInclVat - (int) $portion['totalAmount'],
                    (int) ceil($quantity) + 1,
                    $amountTaken
                );
            }
            if ($amountPortion !== null) {
                $this->record($captureId, $reference . $suffix, $amountPortion);
                $lines[] = $amountPortion;
            }
        }

        return $lines;
    }

    /**
     * The item's lines carrying exactly what Magento credits for it.
     *
     * Magento splits a discounted line over its credit memos from the order's remaining
     * amounts, not the invoices', so a credit memo can credit a minor unit more or less
     * discount than the capture holds for those units. The discount line takes the
     * credit memo's discount but never more than the capture still holds of it (Briqpay
     * refuses that); any part it cannot take goes on the item line. Item plus discount
     * is always Magento's amount for the line.
     *
     * @param SessionLedger $ledger
     * @param array<string,mixed> $capture
     * @param array<string,mixed> $line the capture's item line
     * @param string $reference
     * @param float $quantity
     * @param array<string,int> $document CaptureOrder::documentAmounts()
     * @return array<int, array<string, mixed>>
     */
    private function documentPortions(
        SessionLedger $ledger,
        array $capture,
        array $line,
        string $reference,
        float $quantity,
        array $document
    ): array {
        $captureId = (string) $capture['captureId'];
        $discount = $document['discountInclVat'];
        $discountVat = $document['discountVat'];
        $discountLine = $ledger->captureLine($capture, $reference . '_discount', ['discount']);
        $discountHeld = null;
        if ($discountLine === null) {
            $discount = 0;
            $discountVat = 0;
        } else {
            $taken = $this->taken($ledger, $captureId, $reference . '_discount', ['discount']);
            $held = -((int) $discountLine['totalAmount'] - $taken['totalAmount']);
            $heldVat = -((int) $discountLine['totalVatAmount'] - $taken['totalVatAmount']);
            if ($discount > $held) {
                $discount = $held;
                $discountVat = $heldVat;
            }
            $discountHeld = [$held, $heldVat];
        }
        $charged = $document['grossInclVat'] - $document['discountInclVat'];
        $chargedVat = $document['grossVat'] - $document['discountVat'];
        $lineVat = $chargedVat + $discountVat;

        // Everything this capture still holds of the line, at the amount it holds, where
        // Magento's own VAT split (one unit's VAT can differ by a minor unit between
        // invoice and credit memo) would leave the capture with VAT its remaining lines
        // at that rate cannot carry: the refund takes the VAT the line was captured with,
        // and the difference to Magento's is noted as any VAT difference is.
        $lineTaken = $this->taken($ledger, $captureId, $reference, CaptureCartBuilder::ITEM_TYPES);
        $closes = $quantity >= (float) $line['quantity'] - $lineTaken['quantity'] - LinePortion::QUANTITY_EPSILON;
        $heldVat = (int) $line['totalVatAmount'] - $lineTaken['totalVatAmount'];
        $rate = (int) ($line['taxRate'] ?? 0);
        $pending = [['taxRate' => $rate, 'totalAmount' => $charged + $discount, 'totalVatAmount' => $lineVat]];
        if ($discountLine !== null && $discount !== 0) {
            // The line's own discount leaves the capture with it.
            $pending[] = [
                'taxRate' => (int) ($discountLine['taxRate'] ?? 0),
                'totalAmount' => -$discount,
                'totalVatAmount' => -$discountVat,
            ];
        }
        if ($closes && $lineVat !== $heldVat
            && $charged + $discount === (int) $line['totalAmount'] - $lineTaken['totalAmount']
            && $this->strandsVat($ledger, $capture, $pending, $rate)
        ) {
            // Noted as a VAT difference; the reconciliation to Magento's total counts
            // the line at Magento's VAT, so it does not move the difference back.
            $this->vatDifference += $heldVat - $lineVat;
            $this->closedShift += $heldVat - $lineVat;
            $lineVat = $heldVat;
            $this->closedLines[$reference] = true;
            // Its discount, when this refund takes all the capture holds of it, keeps
            // that VAT too.
            if ($discountHeld !== null && [$discount, $discountVat] === $discountHeld) {
                $this->closedLines[$reference . '_discount'] = true;
            }
        }

        $portion = $this->linePortion->documentPortion(
            $line,
            $quantity,
            $charged + $discount,
            $lineVat
        );
        $this->record($captureId, $reference, $portion);
        $lines = [$portion];
        if ($discountLine !== null) {
            $amountPortion = $this->linePortion->documentAmountPortion($discountLine, -$discount, -$discountVat);
            if ($amountPortion !== null) {
                $this->record($captureId, $reference . '_discount', $amountPortion);
                $lines[] = $amountPortion;
            }
        }

        return $lines;
    }

    /**
     * Adds the credited shipping to the cart of the first capture that still holds shipping.
     *
     * @param SessionLedger $ledger
     * @param array<string,array<int,array<string,mixed>>> $carts
     * @param int $shippingInclTax
     * @param bool $shippingCompleted
     * @return void
     */
    private function addShipping(
        SessionLedger $ledger,
        array &$carts,
        int $shippingInclTax,
        bool $shippingCompleted
    ): void {
        foreach ($ledger->captures() as $capture) {
            $captureId = (string) $capture['captureId'];
            $line = $ledger->captureLine($capture, 'shipping', ['shipping_fee']);
            if ($line === null) {
                continue;
            }
            $refunded = $this->taken($ledger, $captureId, 'shipping', ['shipping_fee']);
            $refundedPartially = $this->taken($ledger, $captureId, 'shipping_refund', ['adjustment']);
            $remaining = (int) $line['totalAmount'] - $refunded['totalAmount'] - $refundedPartially['totalAmount'];
            if ($remaining <= 0) {
                continue;
            }

            $completes = $shippingCompleted || $shippingInclTax >= $remaining;
            if ($completes && $refundedPartially['totalAmount'] === 0) {
                $portion = $this->linePortion->quantityPortion(
                    $line,
                    (float) $line['quantity'] - $refunded['quantity'],
                    $refunded
                );
                $this->record($captureId, 'shipping', $portion);
                $carts[$captureId][] = $portion;

                return;
            }

            // Briqpay prices shipping per unit, so part of it, or whatever is left after
            // an earlier partial refund, can only go back as an adjustment. Its VAT is
            // the same share of the shipping line's VAT.
            $amount = $completes ? $remaining : $shippingInclTax;
            $vat = $completes
                ? (int) $line['totalVatAmount'] - $refunded['totalVatAmount'] - $refundedPartially['totalVatAmount']
                : MinorUnits::divRound((int) $line['totalVatAmount'] * $amount, (int) $line['totalAmount']);
            $adjustment = self::adjustmentLine(
                'shipping_refund',
                (string) ($line['name'] ?? 'Shipping'),
                $amount,
                $vat,
                (int) $line['taxRate']
            );
            $this->record($captureId, 'shipping_refund', $adjustment);
            $carts[$captureId][] = $adjustment;

            return;
        }
    }

    /**
     * The capture's rounding line, when this refund leaves nothing else on it.
     *
     * @param SessionLedger $ledger
     * @param array<string,mixed> $capture
     * @return array<string, mixed>|null
     */
    private function roundingPortion(SessionLedger $ledger, array $capture): ?array
    {
        $captureId = (string) $capture['captureId'];
        $rounding = $ledger->captureLine($capture, 'rounding', ['surcharge']);
        if ($rounding === null) {
            return null;
        }
        foreach ($capture['cart'] ?? [] as $line) {
            $reference = (string) ($line['reference'] ?? '');
            if ($reference === 'rounding') {
                continue;
            }
            $captured = $ledger->captureLine($capture, $reference);
            $refunded = $this->taken($ledger, $captureId, $reference, [])['totalAmount'];
            if ($reference === 'shipping') {
                $refunded += $this->taken($ledger, $captureId, 'shipping_refund', ['adjustment'])['totalAmount'];
            }
            if ($captured !== null && (int) $captured['totalAmount'] !== $refunded) {
                return null;
            }
        }

        return $this->linePortion->amountPortion(
            $rounding,
            1,
            1,
            true,
            $this->taken($ledger, $captureId, 'rounding', ['surcharge'])
        );
    }

    /**
     * Refunded so far from a capture for a reference: earlier refunds plus what this credit memo has already taken.
     *
     * @param SessionLedger $ledger
     * @param string $captureId
     * @param string $reference
     * @param array<int,string> $types
     * @return array{quantity: float, totalAmount: int, totalVatAmount: int}
     */
    private function taken(SessionLedger $ledger, string $captureId, string $reference, array $types): array
    {
        $earlier = $ledger->refunded($captureId, $reference, $types);
        $now = $this->taken[$captureId][$reference] ?? ['quantity' => 0.0, 'totalAmount' => 0, 'totalVatAmount' => 0];

        return [
            'quantity' => $earlier['quantity'] + $now['quantity'],
            'totalAmount' => $earlier['totalAmount'] + $now['totalAmount'],
            'totalVatAmount' => $earlier['totalVatAmount'] + $now['totalVatAmount'],
        ];
    }

    /**
     * Records a portion this credit memo takes from a capture.
     *
     * @param string $captureId
     * @param string $reference
     * @param array<string,mixed> $portion
     * @return void
     */
    private function record(string $captureId, string $reference, array $portion): void
    {
        $now = $this->taken[$captureId][$reference] ?? ['quantity' => 0.0, 'totalAmount' => 0, 'totalVatAmount' => 0];
        $this->taken[$captureId][$reference] = [
            'quantity' => $now['quantity'] + (float) $portion['quantity'],
            'totalAmount' => $now['totalAmount'] + (int) $portion['totalAmount'],
            'totalVatAmount' => $now['totalVatAmount'] + (int) $portion['totalVatAmount'],
        ];
    }

    /**
     * An adjustment line: an amount of its own, not a portion of a captured line.
     *
     * @param string $reference
     * @param string $name
     * @param int $total
     * @param int $vat
     * @param int $taxRate
     * @return array<string, mixed>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- private pure helper, never intercepted
    private static function adjustmentLine(string $reference, string $name, int $total, int $vat, int $taxRate): array
    {
        return [
            'productType' => 'adjustment',
            'reference' => $reference,
            'name' => $name,
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => $total - $vat,
            'taxRate' => $taxRate,
            'discountPercentage' => 0,
            'unitPriceIncVat' => $total,
            'totalAmount' => $total,
            'totalVatAmount' => $vat,
        ];
    }
}
