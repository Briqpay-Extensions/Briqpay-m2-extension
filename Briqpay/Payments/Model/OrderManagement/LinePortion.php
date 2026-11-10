<?php
/**
 * Takes a portion of a line Briqpay already holds, for a capture or a refund.
 *
 * A portion keeps the line's reference, name, product type, tax rate and unit
 * prices of the line it is taken from. Only quantity and totals change, and
 * the portion that completes a line takes exactly what remains of it, so however a
 * line is split its portions always add up to the line itself.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Utility\Money\MinorUnits;

class LinePortion
{
    /**
     * Quantities below this are float noise, not a real remainder.
     */
    public const QUANTITY_EPSILON = 0.00001;

    /**
     * A portion of a quantity-based line (an item or shipping).
     *
     * @param array<string,mixed> $line the line as Briqpay holds it
     * @param float $quantity quantity in this portion
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int} $taken already captured or refunded
     * @return array<string, mixed>
     */
    public function quantityPortion(array $line, float $quantity, array $taken): array
    {
        $remainingQuantity = self::remainingQuantity($line, $taken);
        if ($quantity >= $remainingQuantity - self::QUANTITY_EPSILON) {
            $total = (int) $line['totalAmount'] - $taken['totalAmount'];
            $vat = (int) $line['totalVatAmount'] - $taken['totalVatAmount'];
            // The portion that completes the line takes exactly the remaining quantity,
            // computed by subtracting each earlier quantity in turn.
            $quantity = $remainingQuantity;
        } else {
            // Same formulas CartLine used to build the line at checkout.
            $total = MinorUnits::round((int) $line['unitPriceIncVat'] * $quantity);
            $vat = MinorUnits::round((int) $line['taxRate'] * (int) $line['unitPrice'] * $quantity / 10000);
        }

        return $this->withTotals($line, self::quantityValue($quantity), $total, $vat);
    }

    /**
     * A portion of an amount-based line.
     *
     * A discount, a WEEE surcharge, or a rounding line of an earlier session.
     *
     * Its totals are in proportion to the share of its item being captured or refunded.
     * Returns null when nothing of the line is left.
     *
     * @param array<string,mixed> $line the line as Briqpay holds it
     * @param float $share
     * @param float $whole
     * @param bool $completesLine
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int} $taken already captured or refunded
     * @return array<string, mixed>|null
     */
    public function amountPortion(array $line, float $share, float $whole, bool $completesLine, array $taken): ?array
    {
        $remainingTotal = (int) $line['totalAmount'] - $taken['totalAmount'];
        $remainingVat = (int) $line['totalVatAmount'] - $taken['totalVatAmount'];
        if ($remainingTotal === 0 && $remainingVat === 0) {
            return null;
        }

        if ($completesLine || $whole <= 0) {
            $total = $remainingTotal;
            $vat = $remainingVat;
        } else {
            $total = MinorUnits::divRound((int) $line['totalAmount'] * $share, $whole);
            $vat = MinorUnits::divRound((int) $line['totalVatAmount'] * $share, $whole);
        }
        if ($total === 0 && $vat === 0) {
            return null;
        }

        $portion = $this->withTotals($line, 1, $total, $vat);
        $portion['unitPriceIncVat'] = $total;
        $portion['unitPrice'] = $total - $vat;

        return $portion;
    }

    /**
     * The item's discount portion that makes item and discount come to Magento's charge for the line.
     *
     * Magento splits a row's tax and discount over its invoices and credit memos in its
     * own way, so a document's share of a line can be a minor unit away from the even
     * split. The item line has to keep Briqpay's unit price, so the difference goes on the
     * discount line, which is taken by amount. Its VAT stays the even split. Falls
     * back to the even split when the target is not within $tolerance of it or would take
     * more than the discount line has left.
     *
     * @param array<string,mixed>|null $evenPortion what amountPortion() gives
     * @param array<string,mixed> $line the discount line as Briqpay holds it
     * @param int $targetTotal the discount total that makes the document's line match Magento
     * @param int $tolerance largest difference from the even split accepted, minor units
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int} $taken already captured or refunded
     * @return array<string, mixed>|null
     */
    public function amountPortionTowards(
        ?array $evenPortion,
        array $line,
        int $targetTotal,
        int $tolerance,
        array $taken
    ): ?array {
        $evenTotal = $evenPortion === null ? 0 : (int) $evenPortion['totalAmount'];
        $vat = $evenPortion === null ? 0 : (int) $evenPortion['totalVatAmount'];
        $remainingTotal = (int) $line['totalAmount'] - $taken['totalAmount'];
        $withinRemaining = $remainingTotal < 0
            ? $targetTotal >= $remainingTotal && $targetTotal <= 0
            : $targetTotal <= $remainingTotal && $targetTotal >= 0;
        if ($targetTotal === $evenTotal || abs($targetTotal - $evenTotal) > $tolerance || !$withinRemaining) {
            return $evenPortion;
        }
        if ($targetTotal === 0 && $vat === 0) {
            return null;
        }

        $portion = $this->withTotals($line, 1, $targetTotal, $vat);
        $portion['unitPriceIncVat'] = $targetTotal;
        $portion['unitPrice'] = $targetTotal - $vat;

        return $portion;
    }

    /**
     * A portion of a quantity-based line carrying a Magento document's own amounts.
     *
     * Unit prices stay those of the line; the total and VAT are the document's own.
     *
     * @param array<string,mixed> $line the line as Briqpay holds it
     * @param float $quantity
     * @param int $total the document line's incl.-VAT amount, minor units
     * @param int $vat the document line's VAT, minor units
     * @return array<string, mixed>
     */
    public function documentPortion(array $line, float $quantity, int $total, int $vat): array
    {
        return $this->withTotals($line, self::quantityValue($quantity), $total, $vat);
    }

    /**
     * A portion of an amount-based line (a discount) carrying a Magento document's own amounts.
     *
     * Null when the document has none of it.
     *
     * @param array<string,mixed> $line the line as Briqpay holds it
     * @param int $total incl. VAT, minor units, with the line's sign
     * @param int $vat minor units, with the line's sign
     * @return array<string, mixed>|null
     */
    public function documentAmountPortion(array $line, int $total, int $vat): ?array
    {
        if ($total === 0 && $vat === 0) {
            return null;
        }
        $portion = $this->withTotals($line, 1, $total, $vat);
        $portion['unitPriceIncVat'] = $total;
        $portion['unitPrice'] = $total - $vat;

        return $portion;
    }

    /**
     * Whether a quantity takes everything that remains of a line.
     *
     * @param float $lineQuantity
     * @param float $quantity
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int} $taken
     * @return bool
     */
    public function completes(float $lineQuantity, float $quantity, array $taken): bool
    {
        return $quantity >= $lineQuantity - $taken['quantity'] - self::QUANTITY_EPSILON;
    }

    /**
     * The line with its quantity and totals replaced; the image is dropped from the portion.
     *
     * @param array<string,mixed> $line
     * @param int|float $quantity
     * @param int $total
     * @param int $vat
     * @return array<string, mixed>
     */
    private function withTotals(array $line, $quantity, int $total, int $vat): array
    {
        $portion = $line;
        unset($portion['imageUrl']);
        $portion['quantity'] = $quantity;
        $portion['totalAmount'] = $total;
        $portion['totalVatAmount'] = $vat;

        return $portion;
    }

    /**
     * The line's remaining quantity as Briqpay holds it.
     *
     * The line's quantity less each captured (or refunded) quantity in turn.
     *
     * @param array<string,mixed> $line
     * @param array{quantity:float,quantities?:array<int,float>} $taken
     * @return float
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- private pure helper, never intercepted
    private static function remainingQuantity(array $line, array $taken): float
    {
        $remaining = (float) $line['quantity'];
        if (isset($taken['quantities'])) {
            foreach ($taken['quantities'] as $quantity) {
                $remaining -= (float) $quantity;
            }

            return $remaining;
        }

        return $remaining - (float) $taken['quantity'];
    }

    /**
     * Whole quantities are sent as integers, the way the checkout sent them.
     *
     * @param float $quantity
     * @return int|float
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- private pure helper, never intercepted
    private static function quantityValue(float $quantity)
    {
        return floor($quantity) === $quantity ? (int) $quantity : $quantity;
    }
}
