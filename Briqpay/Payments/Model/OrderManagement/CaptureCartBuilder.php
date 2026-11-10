<?php
/**
 * Builds the cart of a Briqpay capture from the invoiced quantities and the lines
 * Briqpay authorised at checkout (see SessionLedger, LinePortion).
 *
 * Per invoiced item: its portion of the item line, and the same share of the item's
 * discount and WEEE lines. Shipping goes on the first capture, as Magento puts it on
 * the first invoice. A rounding line of a session created by an earlier module version
 * goes on the capture that completes the order.
 *
 * A session whose lines carry Magento's exact amounts (SessionLedger::documentAmounts())
 * is captured with each invoice line's own amounts, so every capture equals its invoice
 * with no rounding. An earlier session is captured by proportion of its lines, the
 * item's discount portion settling Magento's own split of the line
 * (LinePortion::amountPortionTowards()).
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Utility\Money\VatSplit;
use Magento\Framework\Exception\LocalizedException;

class CaptureCartBuilder
{
    public const ITEM_TYPES = ['physical', 'digital'];

    /**
     * @var LinePortion
     */
    private LinePortion $linePortion;

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
     * The capture cart for the invoiced quantities.
     *
     * @param SessionLedger $ledger
     * @param array<int,array{reference:string,quantity:float,chargedInclVat?:int,document?:array<string,int>}> $items
     *        invoiced quantity per item reference, and optionally what Magento charges for that invoice
     *        line and its own amounts (see CaptureOrder::documentAmounts())
     * @return array<int, array<string, mixed>> V3 cart lines
     * @throws LocalizedException when Briqpay holds no line for an item, or less than is invoiced
     */
    public function build(SessionLedger $ledger, array $items): array
    {
        $cart = [];
        $taken = [];
        foreach ($items as $item) {
            $reference = $item['reference'];
            $quantity = $item['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            $line = $ledger->sessionLine($reference, self::ITEM_TYPES);
            if ($line === null) {
                throw new LocalizedException(__(
                    'Briqpay has no authorised line for "%1", so it cannot be captured.',
                    $reference
                ));
            }
            $captured = $this->add($ledger->captured($reference, self::ITEM_TYPES), $taken[$reference] ?? null);
            $remaining = (float) $line['quantity'] - $captured['quantity'];
            if ($quantity > $remaining + LinePortion::QUANTITY_EPSILON) {
                throw new LocalizedException(__(
                    'Only %1 of "%2" remain uncaptured at Briqpay; this invoice has %3.',
                    $remaining,
                    $reference,
                    $quantity
                ));
            }

            $completes = $this->linePortion->completes((float) $line['quantity'], $quantity, $captured);
            // The invoice line's own amounts; the invoice that completes the line takes
            // exactly what remains of it, which is Magento's own remainder.
            $document = $ledger->documentAmounts() ? ($item['document'] ?? null) : null;
            if ($document !== null && !$completes) {
                $document = $this->withinDiscountLeft($ledger, $reference, $document, $taken);
            }
            $portion = $document !== null && !$completes
                ? $this->linePortion->documentPortion(
                    $line,
                    $quantity,
                    $document['grossInclVat'],
                    $document['grossVat']
                )
                : $this->linePortion->quantityPortion($line, $quantity, $captured);
            $cart[] = $portion;
            $taken[$reference] = $this->add($taken[$reference] ?? null, $portion);

            foreach (['_discount' => ['discount'], '_weee_tax' => ['surcharge']] as $suffix => $types) {
                $amountLine = $ledger->sessionLine($reference . $suffix, $types);
                if ($amountLine === null) {
                    continue;
                }
                $amountTaken = $this->add(
                    $ledger->captured($reference . $suffix, $types),
                    $taken[$reference . $suffix] ?? null
                );
                $amountPortion = $suffix === '_discount' && $document !== null && !$completes
                    ? $this->linePortion->documentAmountPortion(
                        $amountLine,
                        -$document['discountInclVat'],
                        -$document['discountVat']
                    )
                    : $this->linePortion->amountPortion(
                        $amountLine,
                        $quantity,
                        (float) $line['quantity'],
                        $completes,
                        $amountTaken
                    );
                if ($suffix === '_discount' && $document === null && !$completes && isset($item['chargedInclVat'])
                    && $ledger->sessionLine($reference . '_weee_tax', ['surcharge']) === null
                ) {
                    $amountPortion = $this->linePortion->amountPortionTowards(
                        $amountPortion,
                        $amountLine,
                        $item['chargedInclVat'] - (int) $portion['totalAmount'],
                        (int) ceil($quantity) + 1,
                        $amountTaken
                    );
                }
                if ($amountPortion !== null) {
                    $cart[] = $amountPortion;
                    $taken[$reference . $suffix] = $this->add($taken[$reference . $suffix] ?? null, $amountPortion);
                }
            }
        }

        $shipping = $ledger->sessionLine('shipping', ['shipping_fee']);
        if ($shipping !== null) {
            $shippingCaptured = $ledger->captured('shipping', ['shipping_fee']);
            if ($shippingCaptured['quantity'] < (float) $shipping['quantity'] - LinePortion::QUANTITY_EPSILON) {
                $portion = $this->linePortion->quantityPortion(
                    $shipping,
                    (float) $shipping['quantity'] - $shippingCaptured['quantity'],
                    $shippingCaptured
                );
                $cart[] = $portion;
                $taken['shipping'] = $this->add(null, $portion);
            }
        }

        $rounding = $ledger->sessionLine('rounding', ['surcharge']);
        if ($rounding !== null && $this->completesOrder($ledger, $taken)) {
            $roundingPortion = $this->linePortion->amountPortion(
                $rounding,
                1,
                1,
                true,
                $ledger->captured('rounding', ['surcharge'])
            );
            if ($roundingPortion !== null) {
                $cart[] = $roundingPortion;
            }
        }

        $this->vatDifference = 0;
        if (!$ledger->documentAmounts()) {
            return $cart;
        }
        $fitted = VatSplit::forceFit(VatSplit::fitLines($cart));
        $this->vatDifference = CaptureOrder::vatTotal($fitted) - CaptureOrder::vatTotal($cart);

        return $fitted;
    }

    /**
     * How much more VAT the last build() captures than Magento's invoice lines carry.
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
     * The invoice line's amounts, its discount no larger than the discount line has left.
     *
     * Magento splits a discount over invoices in proportion, so invoices before the last
     * can together take a minor unit more discount than the line holds, and a discount
     * line never flips sign. The part the discount line cannot take goes on
     * the item line, so item plus discount is still Magento's amount for the line.
     *
     * @param SessionLedger $ledger
     * @param string $reference
     * @param array<string,int> $document CaptureOrder::documentAmounts()
     * @param array<string,array{quantity:float,totalAmount:int,totalVatAmount:int}> $taken
     * @return array<string,int>
     */
    private function withinDiscountLeft(SessionLedger $ledger, string $reference, array $document, array $taken): array
    {
        $line = $ledger->sessionLine($reference . '_discount', ['discount']);
        if ($line === null) {
            return $document;
        }
        $captured = $this->add(
            $ledger->captured($reference . '_discount', ['discount']),
            $taken[$reference . '_discount'] ?? null
        );
        $left = -((int) $line['totalAmount'] - $captured['totalAmount']);
        $leftVat = -((int) $line['totalVatAmount'] - $captured['totalVatAmount']);
        if ($document['discountInclVat'] <= $left) {
            return $document;
        }
        $excess = $document['discountInclVat'] - $left;
        $excessVat = $document['discountVat'] - $leftVat;

        return [
            'grossInclVat' => $document['grossInclVat'] - $excess,
            'grossVat' => $document['grossVat'] - $excessVat,
            'discountInclVat' => $left,
            'discountVat' => $leftVat,
        ];
    }

    /**
     * Whether, with this capture, every authorised line except the rounding line is fully captured.
     *
     * @param SessionLedger $ledger
     * @param array<string,array{quantity:float,totalAmount:int,totalVatAmount:int}> $taken
     * @return bool
     */
    private function completesOrder(SessionLedger $ledger, array $taken): bool
    {
        foreach ($ledger->sessionReferences() as $reference) {
            if ($reference === 'rounding') {
                continue;
            }
            $line = $ledger->sessionLine($reference);
            if ($line === null) {
                continue;
            }
            $captured = $this->add($ledger->captured($reference), $taken[$reference] ?? null);
            if ((int) $line['totalAmount'] !== $captured['totalAmount']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The sum plus one line's quantity and totals; a null sum starts from zero.
     *
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int}|null $sum
     * @param array<string,mixed>|null $line
     * @return array{quantity: float, totalAmount: int, totalVatAmount: int}
     */
    private function add(?array $sum, ?array $line): array
    {
        $sum = $sum ?? ['quantity' => 0.0, 'totalAmount' => 0, 'totalVatAmount' => 0];
        if ($line === null) {
            return $sum;
        }

        return [
            'quantity' => $sum['quantity'] + (float) $line['quantity'],
            'totalAmount' => $sum['totalAmount'] + (int) $line['totalAmount'],
            'totalVatAmount' => $sum['totalVatAmount'] + (int) $line['totalVatAmount'],
        ];
    }
}
