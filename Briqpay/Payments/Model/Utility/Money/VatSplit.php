<?php

namespace Briqpay\Payments\Model\Utility\Money;

/**
 * Splits a discounted row's VAT between its item line and its discount line.
 *
 * Magento rounds a row's tax and its discount tax compensation separately, so taking the
 * item line's VAT from the row before the discount and leaving the discount line the rest
 * can put the discount line's VAT more than a minor unit away from its own rate
 * (24-WB04_discount -6.52 with VAT -1.03 at 19 %, where the rate gives -1.04). A payment
 * method that checks VAT against the rate may refuse such a line.
 *
 * The row's VAT (item VAT less discount VAT) always stays exactly what Magento charges;
 * only how it is shared between the two lines changes, and only when a line does not fit.
 */
class VatSplit
{
    /**
     * Whether a line's VAT fits its rate within one minor unit (VAT 0 at 0 %).
     *
     * @param int $totalInclVat line total incl. VAT, minor units (sign as on the line)
     * @param int $vat line VAT, minor units (same sign)
     * @param int $rateBp tax rate in basis points (1900 = 19 %)
     * @return bool
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure arithmetic, never intercepted
    public static function fits(int $totalInclVat, int $vat, int $rateBp): bool
    {
        if ($rateBp <= 0) {
            return $vat === 0;
        }

        return abs($vat - $totalInclVat * $rateBp / (10000 + $rateBp)) <= 1.0;
    }

    /**
     * The item line's VAT and the discount line's VAT, both positive minor units.
     *
     * A line of several units need not stay divisible by its quantity: the line's total
     * and VAT are sent as they are, and captures match the session's unit price. When
     * no split fits both lines, Magento's split is kept.
     *
     * @param int $grossInclVat the row incl. VAT before the discount
     * @param int $grossVat Magento's VAT on the row before the discount
     * @param int $discountInclVat the discount incl. VAT, positive
     * @param int $discountVat the VAT the discount takes off, positive (0 for a discount after tax)
     * @param int $rateBp tax rate in basis points
     * @param float|int $quantity the item line's quantity
     * @return array{grossVat: int, discountVat: int}
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure arithmetic, never intercepted
    public static function split(
        int $grossInclVat,
        int $grossVat,
        int $discountInclVat,
        int $discountVat,
        int $rateBp,
        $quantity = 1
    ): array {
        $unchanged = ['grossVat' => $grossVat, 'discountVat' => $discountVat];
        if ($discountVat === 0 || $discountInclVat <= 0 || $rateBp <= 0
            || (self::fits($grossInclVat, $grossVat, $rateBp) && self::fits($discountInclVat, $discountVat, $rateBp))
        ) {
            return $unchanged;
        }
        $charged = $grossVat - $discountVat;
        $share = $rateBp / (10000 + $rateBp);
        // How far Magento's charged VAT is from the rate; half of it goes on each line, so
        // neither line carries the whole rounding.
        $off = $charged - ($grossInclVat - $discountInclVat) * $share;
        $best = MinorUnits::round($discountInclVat * $share - $off / 2);
        foreach ([0, -1, 1, -2, 2] as $step) {
            $split = $best + $step;
            $gross = $charged + $split;
            if ($split < 0 || $split > $discountInclVat
                || !self::fits($grossInclVat, $gross, $rateBp)
                || !self::fits($discountInclVat, $split, $rateBp)
            ) {
                continue;
            }

            return ['grossVat' => $gross, 'discountVat' => $split];
        }

        return $unchanged;
    }

    /**
     * Cart lines with VAT shared between lines of the same rate so that each fits it.
     *
     * Magento splits an order line's VAT over its invoices and credit memos unevenly, so a
     * document line can carry VAT its rate cannot give (one MB04 of two, 25.20 with VAT
     * 4.01 at 19 %). Another line of the same rate in the document can often take the
     * difference. Amounts and the VAT per rate stay exactly as they were; a line that
     * cannot be made to fit keeps its VAT.
     *
     * @param array<int,array<string,mixed>> $lines V3 cart lines
     * @return array<int,array<string,mixed>>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure arithmetic, never intercepted
    public static function fitLines(array $lines): array
    {
        foreach (array_keys($lines) as $index) {
            $rate = (int) ($lines[$index]['taxRate'] ?? 0);
            if ($rate <= 0 || self::lineFits($lines[$index])) {
                continue;
            }
            $total = (int) $lines[$index]['totalAmount'];
            $step = MinorUnits::round($total * $rate / (10000 + $rate)) > (int) $lines[$index]['totalVatAmount']
                ? 1
                : -1;
            $tried = $lines;
            while (!self::lineFits($tried[$index])) {
                $partner = self::partner($tried, $index, -$step);
                if ($partner === null) {
                    break;
                }
                $tried = self::withVat($tried, $index, $step);
                $tried = self::withVat($tried, $partner, -$step);
            }
            if (self::lineFits($tried[$index])) {
                $lines = $tried;
            }
        }

        return $lines;
    }

    /**
     * Cart lines with each line's VAT within one minor unit of its rate, none at 0 %.
     *
     * A line's VAT moves no further than needed.
     * For what fitLines() cannot share: Magento's own VAT on a document can be off its
     * rate on every split of its lines (a credit memo of 39.99 with VAT 6.36 at 19 %).
     * Amounts stay as they are; only VAT changes, so the caller notes the difference
     * from Magento's VAT.
     *
     * @param array<int,array<string,mixed>> $lines V3 cart lines
     * @return array<int,array<string,mixed>>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure arithmetic, never intercepted
    public static function forceFit(array $lines): array
    {
        foreach (array_keys($lines) as $index) {
            if (self::lineFits($lines[$index])) {
                continue;
            }
            $rate = (int) ($lines[$index]['taxRate'] ?? 0);
            $vat = (int) $lines[$index]['totalVatAmount'];
            if ($rate <= 0) {
                $lines = self::withVat($lines, $index, -$vat);
                continue;
            }
            $exact = (int) $lines[$index]['totalAmount'] * $rate / (10000 + $rate);
            $nearest = max((int) ceil($exact - 1), min((int) floor($exact + 1), $vat));
            $lines = self::withVat($lines, $index, $nearest - $vat);
        }

        return $lines;
    }

    /**
     * The line of the same rate, other than $index, that best takes $vat more VAT and still fits.
     *
     * @param array<int,array<string,mixed>> $lines
     * @param int $index
     * @param int $vat
     * @return int|null
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure arithmetic, never intercepted
    private static function partner(array $lines, int $index, int $vat): ?int
    {
        $rate = (int) $lines[$index]['taxRate'];
        $best = null;
        $bestDistance = 0.0;
        foreach ($lines as $candidate => $line) {
            $total = (int) ($line['totalAmount'] ?? 0);
            $after = (int) ($line['totalVatAmount'] ?? 0) + $vat;
            if ($candidate === $index || (int) ($line['taxRate'] ?? 0) !== $rate || !self::lineFits($line)
                || !self::fits($total, $after, $rate)
            ) {
                continue;
            }
            $distance = abs($after - $total * $rate / (10000 + $rate));
            if ($best === null || $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Whether a cart line's VAT fits its rate.
     *
     * @param array<string,mixed> $line
     * @return bool
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure arithmetic, never intercepted
    private static function lineFits(array $line): bool
    {
        return self::fits(
            (int) ($line['totalAmount'] ?? 0),
            (int) ($line['totalVatAmount'] ?? 0),
            (int) ($line['taxRate'] ?? 0)
        );
    }

    /**
     * The lines with $vat added to one line's VAT; an amount-based line's unit price follows.
     *
     * @param array<int,array<string,mixed>> $lines
     * @param int $index
     * @param int $vat
     * @return array<int,array<string,mixed>>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure arithmetic, never intercepted
    private static function withVat(array $lines, int $index, int $vat): array
    {
        $lines[$index]['totalVatAmount'] = (int) $lines[$index]['totalVatAmount'] + $vat;
        if (in_array($lines[$index]['productType'] ?? null, ['discount', 'surcharge', 'adjustment'], true)) {
            $lines[$index]['unitPrice'] = (int) $lines[$index]['totalAmount'] - $lines[$index]['totalVatAmount'];
        }

        return $lines;
    }
}
