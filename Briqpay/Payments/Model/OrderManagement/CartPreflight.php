<?php
/**
 * Checks a capture or refund cart before it is sent, using the session read for the same
 * operation (SessionLedger), so no extra request is made.
 *
 * The session is read at the moment of the operation, so changes made in the Briqpay
 * backoffice (a capture, refund or cancel there) are taken into account. A cart that no
 * longer fits what Briqpay holds is stopped here with a message naming the line, and
 * nothing is sent.
 *
 * The module's checks: each line is a portion of the authorised line (or, for a refund,
 * of the captured line) with the same reference, product type, tax rate and unit price;
 * no more is taken than remains; and a line's total and VAT are consistent with its unit
 * price, tax rate and quantity.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\OrderManagement;

use Magento\Framework\Exception\LocalizedException;

class CartPreflight
{
    /**
     * Lines taken by amount, not by quantity.
     */
    private const AMOUNT_BASED_TYPES = ['gift_card', 'discount', 'surcharge', 'sales_tax'];

    /**
     * Checks a capture cart against what the session authorised and has captured.
     *
     * @param SessionLedger $ledger
     * @param array<int,array<string,mixed>> $cart
     * @return void
     * @throws LocalizedException naming the first line Briqpay would refuse
     */
    public function checkCapture(SessionLedger $ledger, array $cart): void
    {
        $taken = [];
        foreach ($cart as $line) {
            if ($this->unmatched($line)) {
                continue;
            }
            $reference = (string) $line['reference'];
            $held = $ledger->sessionLine($reference, [(string) $line['productType']]);
            if ($held === null) {
                throw $this->refusal($line, 'Briqpay holds no authorised line for it');
            }
            $done = $ledger->captured($reference, [(string) $line['productType']]);
            $this->checkLine($line, $held, $done, $taken[$reference] ?? null, 'captured');
            $taken[$reference] = $this->add($taken[$reference] ?? null, $line);
        }
    }

    /**
     * Checks the refund carts of one credit memo, all of them before any is sent.
     *
     * @param SessionLedger $ledger
     * @param array<string,array<int,array<string,mixed>>> $carts cart per capture id
     * @return void
     * @throws LocalizedException naming the first line Briqpay would refuse
     */
    public function checkRefunds(SessionLedger $ledger, array $carts): void
    {
        foreach ($carts as $captureId => $cart) {
            $capture = $this->capture($ledger, (string) $captureId);
            $totals = CaptureOrder::orderTotals($cart);
            if ($totals['amountIncVat'] < 0 || $totals['amountExVat'] < 0) {
                throw new LocalizedException(__(
                    'The refund from capture %1 would be below zero (%2 incl. VAT, %3 excl. VAT); nothing was '
                    . 'sent to Briqpay.',
                    (string) $captureId,
                    number_format($totals['amountIncVat'] / 100, 2, '.', ''),
                    number_format($totals['amountExVat'] / 100, 2, '.', '')
                ));
            }
            $left = $ledger->refundable($capture);
            if ($totals['amountIncVat'] > $left['incVat'] || $totals['amountExVat'] > $left['exVat']) {
                $exceeded = $totals['amountIncVat'] > $left['incVat'] ? 'incl. VAT' : 'excl. VAT';
                $sent = $exceeded === 'incl. VAT' ? $totals['amountIncVat'] : $totals['amountExVat'];
                $held = $exceeded === 'incl. VAT' ? $left['incVat'] : $left['exVat'];
                throw new LocalizedException(__(
                    'The refund from capture %1 (%2 %3) is more than it has left (%4 %3); nothing was sent to Briqpay.',
                    (string) $captureId,
                    number_format($sent / 100, 2, '.', ''),
                    $exceeded,
                    number_format($held / 100, 2, '.', '')
                ));
            }
            $taken = [];
            foreach ($cart as $line) {
                if ($this->unmatched($line)) {
                    continue;
                }
                $reference = (string) $line['reference'];
                $types = [(string) $line['productType']];
                $held = $ledger->captureLine($capture, $reference, $types);
                if ($held === null) {
                    throw $this->refusal($line, sprintf('capture %s holds no such line', $captureId));
                }
                $done = $ledger->refunded((string) $captureId, $reference, $types);
                $this->checkLine($line, $held, $done, $taken[$reference] ?? null, 'refunded');
                $taken[$reference] = $this->add($taken[$reference] ?? null, $line);
            }
        }
    }

    /**
     * Checks one line against the line Briqpay holds for it.
     *
     * @param array<string,mixed> $line the line about to be sent
     * @param array<string,mixed> $held the line Briqpay holds
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int} $done already captured or refunded
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int}|null $taken earlier in this cart
     * @param string $verb
     * @return void
     * @throws LocalizedException
     */
    private function checkLine(array $line, array $held, array $done, ?array $taken, string $verb): void
    {
        $amountBased = in_array($line['productType'], self::AMOUNT_BASED_TYPES, true);
        if ($line['productType'] !== 'gift_card' && (int) $line['taxRate'] !== (int) $held['taxRate']) {
            throw $this->refusal($line, 'its tax rate differs from the one Briqpay holds');
        }
        if (!$amountBased && (int) $line['unitPrice'] !== (int) $held['unitPrice']) {
            throw $this->refusal($line, 'its unit price differs from the one Briqpay holds');
        }

        $quantity = (float) $line['quantity'];
        if ($amountBased) {
            $heldTotal = (int) $held['totalAmount'];
            $left = $heldTotal - $done['totalAmount'] - ($taken['totalAmount'] ?? 0) - (int) $line['totalAmount'];
            if (($heldTotal > 0 && $left < 0) || ($heldTotal < 0 && $left > 0) || ($heldTotal === 0 && $left !== 0)) {
                throw $this->refusal($line, sprintf('it takes more than remains to be %s', $verb));
            }
        } else {
            $left = (float) $held['quantity'] - $done['quantity'] - ($taken['quantity'] ?? 0.0) - $quantity;
            if ($left < -LinePortion::QUANTITY_EPSILON) {
                throw $this->refusal($line, sprintf('its quantity is more than remains to be %s', $verb));
            }
        }

        $tolerance = 100 + abs($quantity);
        if (abs((int) $line['totalAmount'] - (int) $line['unitPriceIncVat'] * $quantity) > $tolerance) {
            throw $this->refusal($line, 'its total does not fit its unit price incl. VAT and quantity');
        }
        $vat = (int) $line['taxRate'] * (int) $line['unitPrice'] * $quantity / 10000;
        if (abs((int) $line['totalVatAmount'] - $vat) > $tolerance) {
            throw $this->refusal($line, 'its VAT does not fit its tax rate, unit price and quantity');
        }
    }

    /**
     * Adjustments and negative surcharges: amounts of their own.
     *
     * They are checked with the capture's totals rather than line by line.
     *
     * @param array<string,mixed> $line
     * @return bool
     */
    private function unmatched(array $line): bool
    {
        return $line['productType'] === 'adjustment'
            || ($line['productType'] === 'surcharge' && (int) $line['unitPrice'] < 0);
    }

    /**
     * The capture with this id, as the session holds it.
     *
     * @param SessionLedger $ledger
     * @param string $captureId
     * @return array<string,mixed>
     * @throws LocalizedException
     */
    private function capture(SessionLedger $ledger, string $captureId): array
    {
        foreach ($ledger->captures() as $capture) {
            if ((string) $capture['captureId'] === $captureId) {
                return $capture;
            }
        }
        throw new LocalizedException(__(
            'Briqpay holds no capture %1 to refund against; nothing was sent to Briqpay.',
            $captureId
        ));
    }

    /**
     * The sum plus one line's quantity and totals; a null sum starts from zero.
     *
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int}|null $sum
     * @param array<string,mixed> $line
     * @return array{quantity:float,totalAmount:int,totalVatAmount:int}
     */
    private function add(?array $sum, array $line): array
    {
        $sum = $sum ?? ['quantity' => 0.0, 'totalAmount' => 0, 'totalVatAmount' => 0];

        return [
            'quantity' => $sum['quantity'] + (float) $line['quantity'],
            'totalAmount' => $sum['totalAmount'] + (int) $line['totalAmount'],
            'totalVatAmount' => $sum['totalVatAmount'] + (int) $line['totalVatAmount'],
        ];
    }

    /**
     * The error for a line Briqpay would refuse.
     *
     * @param array<string,mixed> $line
     * @param string $reason
     * @return LocalizedException
     */
    private function refusal(array $line, string $reason): LocalizedException
    {
        return new LocalizedException(__(
            'Briqpay would refuse the line "%1": %2. Nothing was sent to Briqpay. '
            . 'Check the order in the Briqpay backoffice; it may have been changed there.',
            (string) ($line['reference'] ?? ''),
            $reason
        ));
    }
}
