<?php

namespace Briqpay\Payments\Model\Utility\Money;

/**
 * Builds one Briqpay cart line from the amount Magento actually charges for the row.
 *
 * Briqpay's own totals (both what it validates and what it displays in the iframe) are
 * computed as round(unitPriceIncVat * quantity), not read back from totalAmount - see
 * https://developer.briqpay.com/docs/3.0.0/api/create-session. So unitPriceIncVat is
 * the value the line is built around, and totalAmount here always mirrors that exact
 * formula. unitPrice (ex VAT) is then derived from unitPriceIncVat rather than the
 * other way around, which is what keeps every field internally consistent.
 *
 * A fractional quantity or a row total that doesn't divide evenly by the quantity can
 * leave a remainder - e.g. 4.3 kg at an incl-VAT unit price of 84.87 sums to 364.94,
 * two öre short of the 364.96 Magento actually charges. That remainder is never
 * dropped: CartBalancer compares the finished cart's line totals against the
 * document's real total and settles the aggregate gap once, on a single line.
 *
 * totalVatAmount is computed as round(taxRate * unitPrice * quantity / 10000), not as
 * totalAmount minus the ex-VAT total. Those two formulas agree most of the time but can
 * diverge by real money at scale (confirmed against Briqpay's own capture endpoint,
 * which has rejected captures historically with "taxRate * unitPrice * quantity !=
 * totalVatAmount" - e.g. a 94.38 kr line at quantity 50, 25% VAT, diverges by 25 öre
 * between the two formulas). Briqpay's capture/refund validation checks the multiplication
 * form directly, so that's the one this class computes - matching what actually gets
 * validated matters more than the two formulas agreeing with each other.
 */
class CartLine
{
    /**
     * @param int    $rowTotalIncVat Magento's charged amount for this row, minor units.
     *                                Negative for discount/adjustment lines.
     * @param mixed  $quantity       int or float; passed straight through to the line so a
     *                                whole-unit line (shipping, discount, rounding) keeps
     *                                its integer type and a real quantity keeps its decimals.
     * @param int    $taxRateBp      Tax rate in basis points (25% => 2500).
     */
    public function build(
        int $rowTotalIncVat,
        $quantity,
        int $taxRateBp,
        string $productType,
        string $reference,
        string $name,
        string $quantityUnit = 'pc'
    ): array {
        if ((float) $quantity == 0.0) {
            $quantity = 1;
        }

        $unitIncVat = MinorUnits::divRound($rowTotalIncVat, $quantity);
        $totalAmount = MinorUnits::round($unitIncVat * $quantity);
        $unitPrice = MinorUnits::divRound($unitIncVat * 10000, 10000 + $taxRateBp);
        $totalVatAmount = MinorUnits::round($taxRateBp * $unitPrice * $quantity / 10000);

        return [
            'productType' => $productType,
            'reference' => $reference,
            'name' => $name,
            'quantity' => $quantity,
            'quantityUnit' => $quantityUnit,
            'unitPrice' => $unitPrice,
            'taxRate' => $taxRateBp,
            'discountPercentage' => 0,
            'unitPriceIncVat' => $unitIncVat,
            'totalAmount' => $totalAmount,
            'totalVatAmount' => $totalVatAmount,
        ];
    }
}
