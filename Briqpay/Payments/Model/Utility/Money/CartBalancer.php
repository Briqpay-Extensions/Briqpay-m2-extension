<?php

namespace Briqpay\Payments\Model\Utility\Money;

use Briqpay\Payments\Logger\Logger;

/**
 * Reconciles a finished set of Briqpay cart lines against the amount Magento actually
 * charges for the document (quote grand total, invoice amount, credit memo amount, ...).
 *
 * CartLine::build() cannot always make totalAmount == the row it was given - a
 * fractional quantity or a row total that doesn't divide evenly leaves a remainder of a
 * few minor units. That is a mathematical property of the line, not a bug: for an
 * integer quantity n, the only totalAmount values an integer unit price can ever produce
 * are exact multiples of n, so a target that isn't one of those multiples (e.g. 47190 at
 * quantity 4, which needs a non-integer 11797.5 unit price) is simply unreachable by any
 * per-unit price - no calculation choice changes that.
 *
 * What IS avoidable is turning that remainder into its own visible "Rounding" line. A
 * quantity-1 line has no such restriction - CartLine::build() reproduces any integer row
 * amount for it with zero residual, because there is no rounding step at quantity 1. So
 * this class folds the remainder into an existing quantity-1 shipping or discount line
 * instead, by rebuilding that one line against its own total plus the remainder - which
 * resolves it exactly and leaves the cart's line count unchanged.
 *
 * Deliberately narrow about which lines are eligible, because folding is a silent edit
 * to a line that already means something:
 *   - Never a physical/digital item - that line is a real catalog price, and silently
 *     shifting it a couple minor units is worse than an honestly-labelled Rounding line.
 *   - Never a WEEE surcharge - it's a specific, remittable tax figure, not this class's
 *     to adjust.
 *   - Only into shipping/discount if doing so doesn't flip the line's sign - a shipping
 *     fee going negative, or a "discount" that adds money, is a worse outcome than a
 *     separate Rounding line.
 *   - Never when the sanity guard below has fired - that means the remainder likely
 *     isn't rounding at all, and it needs to stay visible as its own line, not be
 *     absorbed into a fee where it would be harder to notice.
 * A standalone "Rounding" line is the fallback whenever none of the above applies -
 * every line sold in bulk with no eligible shipping/discount line, or the guard tripped.
 *
 * Folding is also disabled outright via $allowFold=false, and it must be for capture
 * and refund. Confirmed live against Briqpay's sandbox: Briqpay stores the "shipping"
 * (or discount) reference's unitPrice from session create/update and cross-checks later
 * capture/refund calls against it. Checkout owns that value, so folding there is safe -
 * there is nothing prior to conflict with. Capture/refund each independently recompute
 * their own line from a different subset of the order (a partial quantity, a specific
 * invoice), so their own residual is generally NOT the one checkout already folded in,
 * and a folded shipping/discount line from capture is then a genuine unitPrice mismatch
 * against what Briqpay already has on file - rejected with "has mismatching unitPrice".
 * A one-shot full-order capture happens to reproduce checkout's exact numbers and can look
 * safe, but that is a coincidence of matching inputs, not a property of folding itself -
 * so capture and refund never fold, regardless of whether a given call happens to be a
 * full or partial capture.
 *
 * The standalone "Rounding" line is ALSO unsafe with $allowFold=false, for the same root
 * reason: confirmed live, Briqpay's capture/refund endpoints reject a reference that was
 * never part of the original checkout session's cart ("has mismatching name and/or
 * reference, productType, reference") - a "rounding" line invented fresh at capture/
 * refund time is exactly such a reference whenever checkout itself never sent one.
 *
 * Also confirmed live: capture/refund's amountIncVat must equal the cart's own line sum
 * exactly ("Calculated cart amount inc vat does not equal provided inc vat amount") -
 * unlike checkout, where Briqpay stores whatever amountIncVat is sent verbatim. So with
 * $allowFold=false there is no target to reconcile against at all: amountIncVat is
 * simply the sum of whatever lines were actually built (item lines prorated to their own
 * captured/refunded quantity, shipping/discount echoed verbatim from the session where
 * present - see CaptureOrder/RefundOrder). $targetIncVat becomes advisory only: it is
 * what the caller's Magento-side figures (an invoice or credit memo total) expect this
 * capture/refund to be, used solely to size the sanity guard and to log a divergence -
 * a few minor units of drift between Magento's own tax engine and this cart's own
 * per-line math is normal and not forced to match; only the cart being internally
 * consistent (Σ lines == amountIncVat) is required by Briqpay, and that now holds by
 * construction rather than by correction.
 */
class CartBalancer
{
    private $logger;
    private $cartLine;

    public function __construct(Logger $logger, CartLine $cartLine)
    {
        $this->logger = $logger;
        $this->cartLine = $cartLine;
    }

    /**
     * @param array $lines        Cart lines as built by CartLine::build().
     * @param int   $targetIncVat The amount Magento expects this document to be, minor
     *                            units. With $allowFold=true (checkout) this becomes
     *                            amountIncVat verbatim and the cart is corrected to sum
     *                            to it. With $allowFold=false (capture/refund) it is
     *                            advisory only - see the class docblock - amountIncVat
     *                            becomes the cart's own natural line sum instead.
     * @param bool  $allowFold    Checkout only. Capture and refund must pass false - see
     *                            the class docblock for why folding there is unsafe.
     * @return array ['cart' => array, 'amountIncVat' => int, 'amountExVat' => int]
     */
    public function balance(array $lines, int $targetIncVat, bool $allowFold = true): array
    {
        $lineSum = 0;
        $qtySum = 0.0;
        foreach ($lines as $line) {
            $lineSum += $line['totalAmount'];
            $qtySum += abs((float) $line['quantity']);
        }

        $diff = $targetIncVat - $lineSum;

        if (!$allowFold) {
            // Capture/refund: never correct the cart to hit $targetIncVat - there is no
            // line here that is safe to touch or invent (see the class docblock).
            // amountIncVat is the cart's own sum, always exactly reconciled by
            // construction; $targetIncVat is only compared against it for a sanity log.
            if ($diff !== 0) {
                $threshold = count($lines) + (int) ceil($qtySum) + 1;
                if (abs($diff) > $threshold) {
                    $this->logger->error(
                        'CartBalancer: capture/refund cart sum diverges from the '
                        . 'expected (Magento-side) amount by more than ordinary rounding '
                        . '- likely a missing line or a mismodelled discount/quantity.',
                        ['diff' => $diff, 'threshold' => $threshold, 'expected' => $targetIncVat, 'lineSum' => $lineSum, 'lines' => $lines]
                    );
                }
            }

            $amountExVat = 0;
            foreach ($lines as $line) {
                $amountExVat += MinorUnits::round($line['unitPrice'] * $line['quantity']);
            }

            return [
                'cart' => $lines,
                'amountIncVat' => $lineSum,
                'amountExVat' => $amountExVat,
            ];
        }

        if ($diff !== 0) {
            // A rounding remainder is bounded by roughly one minor unit per line/unit
            // combination. Anything past that isn't rounding - it's a missing line or
            // a misread discount, and it must stay visible as its own line rather than
            // be absorbed into a fee, where it would be harder to notice. Still
            // reconcile either way: getting the charged total right matters more than
            // a tidy cart.
            $threshold = count($lines) + (int) ceil($qtySum) + 1;
            $guardTripped = abs($diff) > $threshold;
            if ($guardTripped) {
                $this->logger->error(
                    'CartBalancer: rounding residual exceeds sanity threshold - likely a '
                    . 'missing cart line or a mismodelled discount, not ordinary rounding.',
                    [
                        'diff' => $diff,
                        'threshold' => $threshold,
                        'targetIncVat' => $targetIncVat,
                        'lineSum' => $lineSum,
                        'lines' => $lines,
                    ]
                );
            }

            $absorbIndex = $guardTripped ? null : $this->findAbsorbableLineIndex($lines, $diff);

            if ($absorbIndex !== null) {
                $line = $lines[$absorbIndex];
                $rebuilt = $this->cartLine->build(
                    $line['totalAmount'] + $diff,
                    $line['quantity'],
                    $line['taxRate'],
                    $line['productType'],
                    $line['reference'],
                    $line['name'],
                    $line['quantityUnit']
                );
                if (isset($line['imageUrl'])) {
                    $rebuilt['imageUrl'] = $line['imageUrl'];
                }
                $lines[$absorbIndex] = $rebuilt;
            } else {
                $lines[] = [
                    'productType' => 'surcharge',
                    'reference' => 'rounding',
                    'name' => 'Rounding',
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => $diff,
                    'taxRate' => 0,
                    'discountPercentage' => 0,
                    'unitPriceIncVat' => $diff,
                    'totalAmount' => $diff,
                    'totalVatAmount' => 0,
                ];
            }
        }

        $amountExVat = 0;
        foreach ($lines as $line) {
            $amountExVat += MinorUnits::round($line['unitPrice'] * $line['quantity']);
        }

        return [
            'cart' => $lines,
            'amountIncVat' => $targetIncVat,
            'amountExVat' => $amountExVat,
        ];
    }

    /**
     * Finds a quantity-1 shipping or discount line that can take the remainder without
     * flipping its sign. Shipping is tried first - it's the fee least scrutinised by a
     * customer - then a discount. Nothing else is ever a candidate; see the class
     * docblock for why physical/digital items and WEEE surcharges are excluded.
     */
    private function findAbsorbableLineIndex(array $lines, int $diff): ?int
    {
        foreach (['shipping_fee', 'discount'] as $wantedType) {
            foreach ($lines as $index => $line) {
                if ($line['productType'] !== $wantedType || (float) $line['quantity'] !== 1.0) {
                    continue;
                }
                if ($this->staysSignSafe($line, $diff)) {
                    return $index;
                }
            }
        }

        // The refund-only "adjustment" line (net of adjustment fee/refund) has no fixed
        // sign to begin with - it's already a net figure that can land either side of
        // zero - so no sign check applies here.
        foreach ($lines as $index => $line) {
            if ($line['productType'] === 'surcharge'
                && $line['reference'] === 'adjustment'
                && (float) $line['quantity'] === 1.0
            ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A shipping fee must stay >= 0 and a discount must stay <= 0 after absorbing the
     * remainder - crossing zero would turn a fee negative or a discount into a line that
     * adds money, which reads as more wrong than a small separate Rounding line would.
     */
    private function staysSignSafe(array $line, int $diff): bool
    {
        $newTotal = $line['totalAmount'] + $diff;

        return $line['productType'] === 'discount' ? $newTotal <= 0 : $newTotal >= 0;
    }
}
