<?php
/**
 * Briqpay's own record of an order, read once from the session: the authorised cart
 * and every capture and refund with its lines.
 *
 * Capture and refund lines are built as portions of the lines this record holds, not
 * recomputed from Magento's figures.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\OrderManagement;

class SessionLedger
{
    /**
     * Session reference saying the checkout cart carries Magento's exact line amounts,
     * so each capture and refund sends its own document's line amounts. Sessions without
     * it (created before 2.0) are captured and refunded by proportion of their lines.
     */
    public const LINE_AMOUNTS_REFERENCE = 'lineAmounts';
    public const LINE_AMOUNTS_MAGENTO = 'magento';

    private const REJECTED = 'rejected';

    /**
     * @var array<string, mixed>
     */
    private array $session;

    /**
     * @param array<string,mixed> $session the session as read from Briqpay
     */
    public function __construct(array $session)
    {
        $this->session = $session;
    }

    /**
     * Whether the session's lines carry Magento's exact amounts (LINE_AMOUNTS_REFERENCE).
     *
     * @return bool
     */
    public function documentAmounts(): bool
    {
        return ($this->session['references'][self::LINE_AMOUNTS_REFERENCE] ?? null) === self::LINE_AMOUNTS_MAGENTO;
    }

    /**
     * Whether the customer completed the session (paid, or the payment is pending).
     */
    public function completed(): bool
    {
        return ($this->session['status'] ?? null) === 'completed';
    }

    /**
     * Briqpay's order status for the payment module, null before the customer paid.
     */
    public function orderStatus(): ?string
    {
        return $this->session['moduleStatus']['payment']['orderStatus'] ?? null;
    }

    /**
     * The authorised line with this reference, or null.
     *
     * Quantities and totals are summed when the cart holds it more than once.
     *
     * @param string $reference
     * @param array<int,string> $productTypes
     * @return array<string, mixed>|null
     */
    public function sessionLine(string $reference, array $productTypes = []): ?array
    {
        return $this->sumLines($this->session['data']['order']['cart'] ?? [], $reference, $productTypes);
    }

    /**
     * What has been refunded from a capture so far, incl. VAT, minor units.
     *
     * @param string $captureId
     * @param string $field amountIncVat or amountExVat
     * @return int
     */
    public function refundedTotal(string $captureId, string $field = 'amountIncVat'): int
    {
        $total = 0;
        foreach ($this->session['data']['refunds'] ?? [] as $refund) {
            if (($refund['status'] ?? null) !== self::REJECTED && ($refund['parentCaptureId'] ?? null) === $captureId) {
                $total += (int) ($refund[$field] ?? 0);
            }
        }

        return $total;
    }

    /**
     * What a capture has left to refund per VAT rate: incl. VAT and VAT, minor units.
     *
     * @param array<string,mixed> $capture
     * @param array<int,array<string,mixed>> $pending lines about to be refunded from it
     * @return array<int,array{incVat:int,vat:int}> keyed by tax rate
     */
    public function refundableByRate(array $capture, array $pending = []): array
    {
        $left = [];
        $add = static function (array $line, int $sign) use (&$left): void {
            $rate = (int) ($line['taxRate'] ?? 0);
            $left[$rate] = $left[$rate] ?? ['incVat' => 0, 'vat' => 0];
            $left[$rate]['incVat'] += $sign * (int) ($line['totalAmount'] ?? 0);
            $left[$rate]['vat'] += $sign * (int) ($line['totalVatAmount'] ?? 0);
        };
        foreach ($capture['cart'] ?? [] as $line) {
            $add($line, 1);
        }
        foreach ($this->session['data']['refunds'] ?? [] as $refund) {
            if (($refund['status'] ?? null) === self::REJECTED
                || ($refund['parentCaptureId'] ?? null) !== (string) $capture['captureId']
            ) {
                continue;
            }
            foreach ($refund['cart'] ?? [] as $line) {
                $add($line, -1);
            }
        }
        foreach ($pending as $line) {
            $add($line, -1);
        }

        return $left;
    }

    /**
     * What a capture has left to refund, incl. and excl. VAT, minor units.
     *
     * A refund takes no more than either.
     *
     * @param array<string,mixed> $capture
     * @return array{incVat:int,exVat:int}
     */
    public function refundable(array $capture): array
    {
        $captureId = (string) $capture['captureId'];
        $incVat = (int) ($capture['amountIncVat'] ?? 0);

        return [
            'incVat' => $incVat - $this->refundedTotal($captureId),
            'exVat' => (int) ($capture['amountExVat'] ?? $incVat) - $this->refundedTotal($captureId, 'amountExVat'),
        ];
    }

    /**
     * The ledger as it would be without these captures and their refunds.
     *
     * @param array<int,string> $captureIds
     * @return self
     */
    public function without(array $captureIds): self
    {
        $session = $this->session;
        $session['data']['captures'] = array_values(array_filter(
            $session['data']['captures'] ?? [],
            static function (array $capture) use ($captureIds): bool {
                return !in_array((string) ($capture['captureId'] ?? ''), $captureIds, true);
            }
        ));
        $session['data']['refunds'] = array_values(array_filter(
            $session['data']['refunds'] ?? [],
            static function (array $refund) use ($captureIds): bool {
                return !in_array((string) ($refund['parentCaptureId'] ?? ''), $captureIds, true);
            }
        ));

        return new self($session);
    }

    /**
     * Captures Briqpay has not rejected, in the order they were made.
     *
     * @return array<int, array<string, mixed>>
     */
    public function captures(): array
    {
        return array_values(array_filter(
            $this->session['data']['captures'] ?? [],
            static function (array $capture): bool {
                return ($capture['status'] ?? null) !== self::REJECTED;
            }
        ));
    }

    /**
     * Quantity and totals captured so far for a reference, across all captures.
     *
     * @param string $reference
     * @param array<int,string> $productTypes
     * @return array{quantity: float, totalAmount: int, totalVatAmount: int, quantities: array<int, float>}
     */
    public function captured(string $reference, array $productTypes = []): array
    {
        $sum = self::zero();
        foreach ($this->captures() as $capture) {
            $line = $this->sumLines($capture['cart'] ?? [], $reference, $productTypes);
            if ($line !== null) {
                $sum = self::add($sum, $line);
            }
        }

        return $sum;
    }

    /**
     * The line with this reference on one capture, or null.
     *
     * @param array<string,mixed> $capture
     * @param string $reference
     * @param array<int,string> $productTypes
     * @return array<string, mixed>|null
     */
    public function captureLine(array $capture, string $reference, array $productTypes = []): ?array
    {
        return $this->sumLines($capture['cart'] ?? [], $reference, $productTypes);
    }

    /**
     * Quantity and totals already refunded from one capture for a reference.
     *
     * @param string $captureId
     * @param string $reference
     * @param array<int,string> $productTypes
     * @return array{quantity: float, totalAmount: int, totalVatAmount: int, quantities: array<int, float>}
     */
    public function refunded(string $captureId, string $reference, array $productTypes = []): array
    {
        $sum = self::zero();
        foreach ($this->session['data']['refunds'] ?? [] as $refund) {
            if (($refund['status'] ?? null) === self::REJECTED
                || ($refund['parentCaptureId'] ?? null) !== $captureId
            ) {
                continue;
            }
            $line = $this->sumLines($refund['cart'] ?? [], $reference, $productTypes);
            if ($line !== null) {
                $sum = self::add($sum, $line);
            }
        }

        return $sum;
    }

    /**
     * References of every authorised line.
     *
     * @return array<int, string>
     */
    public function sessionReferences(): array
    {
        $references = [];
        foreach ($this->session['data']['order']['cart'] ?? [] as $line) {
            $references[(string) ($line['reference'] ?? '')] = true;
        }

        return array_map('strval', array_keys($references));
    }

    /**
     * The lines of a cart with this reference, summed into one, or null when there are none.
     *
     * @param array<int,array<string,mixed>> $cart
     * @param string $reference
     * @param array<int,string> $productTypes
     * @return array<string, mixed>|null
     */
    private function sumLines(array $cart, string $reference, array $productTypes): ?array
    {
        $result = null;
        foreach ($cart as $line) {
            if ((string) ($line['reference'] ?? '') !== $reference) {
                continue;
            }
            if ($productTypes !== [] && !in_array($line['productType'] ?? '', $productTypes, true)) {
                continue;
            }
            if ($result === null) {
                $result = $line;
                $result['quantity'] = (float) ($line['quantity'] ?? 0);
                $result['totalAmount'] = (int) ($line['totalAmount'] ?? 0);
                $result['totalVatAmount'] = (int) ($line['totalVatAmount'] ?? 0);
                continue;
            }
            $result['quantity'] += (float) ($line['quantity'] ?? 0);
            $result['totalAmount'] += (int) ($line['totalAmount'] ?? 0);
            $result['totalVatAmount'] += (int) ($line['totalVatAmount'] ?? 0);
        }

        return $result;
    }

    // phpcs:disable Magento2.Functions.StaticFunction -- private pure helpers, never intercepted

    /**
     * An empty sum of quantity and totals.
     *
     * @return array{quantity: float, totalAmount: int, totalVatAmount: int, quantities: array<int, float>}
     */
    private static function zero(): array
    {
        return ['quantity' => 0.0, 'totalAmount' => 0, 'totalVatAmount' => 0, 'quantities' => []];
    }

    /**
     * The sum plus one line's quantity and totals.
     *
     * @param array{quantity:float,totalAmount:int,totalVatAmount:int} $sum
     * @param array<string,mixed> $line
     * @return array{quantity: float, totalAmount: int, totalVatAmount: int, quantities: array<int, float>}
     */
    private static function add(array $sum, array $line): array
    {
        return [
            'quantity' => $sum['quantity'] + (float) $line['quantity'],
            'totalAmount' => $sum['totalAmount'] + (int) $line['totalAmount'],
            'totalVatAmount' => $sum['totalVatAmount'] + (int) $line['totalVatAmount'],
            // Each quantity in turn, as they are subtracted one by one.
            'quantities' => array_merge($sum['quantities'] ?? [], [(float) $line['quantity']]),
        ];
    }

    // phpcs:enable Magento2.Functions.StaticFunction
}
