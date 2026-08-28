<?php

namespace Briqpay\Payments\Model\Utility\Money;

/**
 * Integer minor-unit (öre/cent) arithmetic.
 *
 * Every amount that crosses into a Briqpay cart line passes through fromFloat() once,
 * at the boundary with Magento's float totals, and stays an int for the rest of the
 * pipeline. No float re-enters the calculation after that conversion.
 */
class MinorUnits
{
    /**
     * Converts a major-unit float (e.g. Magento's 94.38) to minor units (9438).
     */
    public static function fromFloat($value): int
    {
        return self::round(((float) $value) * 100);
    }

    /**
     * Rounds to the nearest int, half away from zero on both sides of zero - PHP's
     * round() already behaves this way, which is what keeps negative (discount,
     * adjustment) lines symmetric with positive ones.
     */
    public static function round(float $value): int
    {
        return (int) round($value);
    }

    /**
     * Integer division with the same half-away-from-zero rounding as round().
     * Returns 0 for a zero denominator rather than dividing by it.
     */
    public static function divRound($numerator, $denominator): int
    {
        $denominator = (float) $denominator;
        if ($denominator === 0.0) {
            return 0;
        }

        return self::round(((float) $numerator) / $denominator);
    }
}
