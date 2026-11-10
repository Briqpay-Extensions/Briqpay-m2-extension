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
 * totalVatAmount is computed as round(taxRate * unitPrice * quantity / 10000), not as
 * totalAmount minus the ex-VAT total. Those two formulas agree most of the time but can
 * diverge by real money at scale (a 94.38 kr line at quantity 50, 25% VAT, diverges by
 * 25 öre between them); the line's VAT follows its rate, unit price and quantity.
 */
class CartLine
{
    /**
     * @param int    $rowTotalIncVat Magento's charged amount for this row, minor units.
     *                                Negative for discount/adjustment lines.
     * @param mixed  $quantity       int or float; passed straight through to the line so a
     *                                whole-unit line (shipping, discount) keeps
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
        self::assertWhole($quantity, $reference);
        $quantity = (int) round((float) $quantity);

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

    /**
     * A line carrying Magento's own amounts: totalAmount and totalVatAmount are exactly
     * the incl.-VAT total and VAT given, so lines add up to Magento's totals without any
     * rounding line. Only the unit prices are derived from them, rounded half away from
     * zero; the total and VAT are sent as they are.
     *
     * @param int $totalIncVat the line's incl.-VAT total, minor units
     * @param int|float $quantity
     * @param int $taxRateBp
     * @param int|null $totalVat the line's VAT, minor units; derived from the total and rate when null
     * @param string $productType
     * @param string $reference
     * @param string $name
     * @param string $quantityUnit
     * @return array<string, mixed>
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function buildExact(
        int $totalIncVat,
        $quantity,
        int $taxRateBp,
        ?int $totalVat,
        string $productType,
        string $reference,
        string $name,
        string $quantityUnit = 'pc'
    ): array {
        if ((float) $quantity == 0.0) {
            $quantity = 1;
        }
        self::assertWhole($quantity, $reference);
        $quantity = (int) round((float) $quantity);
        $vat = $totalVat ?? MinorUnits::divRound($totalIncVat * $taxRateBp, 10000 + $taxRateBp);

        return [
            'productType' => $productType,
            'reference' => $reference,
            'name' => $name,
            'quantity' => $quantity,
            'quantityUnit' => $quantityUnit,
            'unitPrice' => MinorUnits::divRound($totalIncVat - $vat, $quantity),
            'taxRate' => $taxRateBp,
            'discountPercentage' => 0,
            'unitPriceIncVat' => MinorUnits::divRound($totalIncVat, $quantity),
            'totalAmount' => $totalIncVat,
            'totalVatAmount' => $vat,
        ];
    }

    /**
     * Briqpay takes whole quantities only; a cart with a decimal quantity is refused
     * before any line is built (GenerateCart), so this never fires for a customer.
     *
     * @param int|float $quantity
     * @param string $reference
     * @return void
     */
    private static function assertWhole($quantity, string $reference): void
    {
        if (abs((float) $quantity - round((float) $quantity)) > 0.000001) {
            throw new \InvalidArgumentException(
                'Briqpay takes whole quantities; "' . $reference . '" has ' . $quantity . '.'
            );
        }
    }
}
