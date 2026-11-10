<?php

namespace Briqpay\Payments\Model\Utility\Money;

use Briqpay\Payments\Logger\Logger;

/**
 * Checks a finished set of Briqpay cart lines against the amount Magento charges for the
 * quote.
 *
 * Since 2.0 every line carries Magento's own row amount (CartLine::buildExact()), so the
 * lines sum to the grand total exactly whenever every Magento total has a line. A
 * difference is therefore never rounding: it is an amount Magento charges that nothing in
 * the cart models (store credit, a gift card, reward points, a fee from another module).
 * Such a cart is refused with a logged error naming the amount and the Magento totals,
 * never patched: a "Rounding" line or a quietly adjusted shipping line would reach the
 * merchant's accounting and Briqpay's captures and refunds as money that is not real.
 *
 * With $allowFold = false (capture and refund), the lines are returned with their own sum
 * and a large divergence is only logged: those documents are reconciled against Briqpay's
 * records by the order management code itself.
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
     * @param array<int, array<string, mixed>> $lines
     * @param int $targetIncVat the amount Magento charges, minor units
     * @param bool $allowFold false for capture and refund documents
     * @param array<string, float> $magentoTotals the quote's non-zero totals by code, for the log
     * @return array{cart: array<int, array<string, mixed>>, amountIncVat: int, amountExVat: int}
     * @throws \Magento\Framework\Exception\LocalizedException when the cart does not add up to what Magento charges
     */
    public function balance(array $lines, int $targetIncVat, bool $allowFold = true, array $magentoTotals = []): array
    {
        $lineSum = 0;
        $qtySum = 0.0;
        foreach ($lines as $line) {
            $lineSum += (int) $line['totalAmount'];
            $qtySum += (float) $line['quantity'];
        }
        $diff = $targetIncVat - $lineSum;

        if ($diff !== 0 && !$allowFold) {
            $threshold = count($lines) + (int) ceil($qtySum) + 1;
            if (abs($diff) > $threshold) {
                $this->logger->error(
                    'CartBalancer (capture/refund mode): line sum diverges from the '
                    . 'expected (Magento-side) amount by more than ordinary rounding '
                    . '- likely a missing line or a mismodelled discount/quantity.',
                    ['diff' => $diff, 'threshold' => $threshold, 'expected' => $targetIncVat, 'lineSum' => $lineSum, 'lines' => $lines]
                );
            }
        } elseif ($diff !== 0) {
            $this->logger->error(
                'The cart does not add up to what Magento charges: a Magento total has no Briqpay line. '
                . 'No session was created.',
                [
                    'grandTotal' => $targetIncVat,
                    'lineSum' => $lineSum,
                    'difference' => $diff,
                    'magentoTotals' => $magentoTotals,
                    'lines' => $lines,
                ]
            );
            throw new \Magento\Framework\Exception\LocalizedException(__(
                'The cart total (%1) differs from the sum of its lines (%2) by %3 minor units: a Magento total '
                . '(%4) has no Briqpay line. No session was created.',
                $targetIncVat,
                $lineSum,
                $diff,
                implode(', ', array_keys($magentoTotals)) ?: 'unknown'
            ));
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
}
