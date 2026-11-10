<?php
/**
 * A capture or refund cart is checked against the module's rules and against what the
 * session holds at that moment, including changes made in the Briqpay backoffice, before
 * anything is sent.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\OrderManagement;

use Briqpay\Payments\Model\OrderManagement\CartPreflight;
use Briqpay\Payments\Model\OrderManagement\SessionLedger;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\OrderManagement\CartPreflight
 * @uses \Briqpay\Payments\Model\OrderManagement\SessionLedger
 */
class CartPreflightTest extends TestCase
{
    public function testCartThatFitsTheSessionPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new CartPreflight())->checkCapture($this->ledger([]), [
            $this->item(1),
            $this->discount(-320, -64),
            $this->shipping(),
        ]);
    }

    public function testLineCapturedInTheBackofficeCannotBeCapturedAgain(): void
    {
        // Both units were already captured in the Briqpay backoffice.
        $ledger = $this->ledger([$this->capture('cap-1', 6400, [$this->item(2)])]);

        $this->expectRefusal('its quantity is more than remains to be captured');
        (new CartPreflight())->checkCapture($ledger, [$this->item(1)]);
    }

    public function testDiscountTakingMoreThanRemainsIsRefused(): void
    {
        $this->expectRefusal('it takes more than remains to be captured');
        (new CartPreflight())->checkCapture($this->ledger([]), [$this->item(2), $this->discount(-700, -140)]);
    }

    public function testUnitPriceDifferentFromTheSessionIsRefused(): void
    {
        $line = $this->item(1);
        $line['unitPrice'] = 2561;

        $this->expectRefusal('its unit price differs');
        (new CartPreflight())->checkCapture($this->ledger([]), [$line]);
    }

    public function testTaxRateDifferentFromTheSessionIsRefused(): void
    {
        $line = $this->item(1);
        $line['taxRate'] = 1200;

        $this->expectRefusal('its tax rate differs');
        (new CartPreflight())->checkCapture($this->ledger([]), [$line]);
    }

    public function testLineTheSessionDoesNotHoldIsRefused(): void
    {
        $line = $this->item(1);
        $line['reference'] = 'SKU-OTHER';

        $this->expectRefusal('Briqpay holds no authorised line');
        (new CartPreflight())->checkCapture($this->ledger([]), [$line]);
    }

    public function testTotalThatDoesNotFitItsUnitPriceIsRefused(): void
    {
        $line = $this->item(1);
        $line['totalAmount'] = 3400;

        $this->expectRefusal('its total does not fit');
        (new CartPreflight())->checkCapture($this->ledger([]), [$line]);
    }

    public function testRefundAgainstACaptureWithoutTheLineIsRefused(): void
    {
        $ledger = $this->ledger([$this->capture('cap-1', 4900, [$this->shipping()])]);

        $this->expectRefusal('capture cap-1 holds no such line');
        (new CartPreflight())->checkRefunds($ledger, ['cap-1' => [$this->item(1)]]);
    }

    public function testRefundBeyondWhatTheCaptureHoldsIsRefused(): void
    {
        $ledger = $this->ledger(
            [$this->capture('cap-1', 3200, [$this->item(1)])],
            // Refunded already, in the backoffice.
            [$this->refund('cap-1', 0, [$this->item(1)])]
        );

        $this->expectRefusal('its quantity is more than remains to be refunded');
        (new CartPreflight())->checkRefunds($ledger, ['cap-1' => [$this->item(1)]]);
    }

    public function testRefundLargerThanTheCaptureHasLeftIsRefused(): void
    {
        $ledger = $this->ledger(
            [$this->capture('cap-1', 6400, [$this->item(2)])],
            [$this->refund('cap-1', 3200, [$this->item(1)])]
        );
        $adjustment = [
            'productType' => 'adjustment', 'reference' => 'adjustment', 'name' => 'Adjustment', 'quantity' => 1,
            'unitPrice' => 100, 'taxRate' => 0, 'unitPriceIncVat' => 100, 'totalAmount' => 100, 'totalVatAmount' => 0,
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is more than it has left (');
        (new CartPreflight())->checkRefunds($ledger, ['cap-1' => [$this->item(1), $adjustment]]);
    }

    public function testAdjustmentLinesAreNotMatched(): void
    {
        $this->expectNotToPerformAssertions();
        $ledger = $this->ledger([$this->capture('cap-1', 3200, [$this->item(1)])]);

        (new CartPreflight())->checkRefunds($ledger, ['cap-1' => [[
            'productType' => 'adjustment', 'reference' => 'adjustment', 'name' => 'Adjustment', 'quantity' => 1,
            'unitPrice' => 1000, 'taxRate' => 0, 'unitPriceIncVat' => 1000,
            'totalAmount' => 1000, 'totalVatAmount' => 0,
        ]]]);
    }

    public function testRefundForAnUnknownCaptureIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Briqpay holds no capture cap-9');

        (new CartPreflight())->checkRefunds($this->ledger([]), ['cap-9' => [$this->item(1)]]);
    }

    private function expectRefusal(string $reason): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($reason);
    }

    /**
     * A session for two of an item at 32.00 incl. 25 % VAT, a 6.40 discount and 49.00 shipping.
     *
     * @param array<int, array<string, mixed>> $captures
     * @param array<int, array<string, mixed>> $refunds
     */
    private function ledger(array $captures, array $refunds = []): SessionLedger
    {
        return new SessionLedger(['data' => [
            'order' => ['cart' => [$this->item(2), $this->discount(-640, -128), $this->shipping()]],
            'captures' => $captures,
            'refunds' => $refunds,
        ]]);
    }

    /**
     * @param array<int, array<string, mixed>> $cart
     * @return array<string, mixed>
     */
    private function capture(string $captureId, int $amountIncVat, array $cart): array
    {
        return ['captureId' => $captureId, 'status' => 'approved', 'amountIncVat' => $amountIncVat, 'cart' => $cart];
    }

    /**
     * @param array<int, array<string, mixed>> $cart
     * @return array<string, mixed>
     */
    private function refund(string $captureId, int $amountIncVat, array $cart): array
    {
        return [
            'refundId' => 'ref-1', 'parentCaptureId' => $captureId, 'status' => 'approved',
            'amountIncVat' => $amountIncVat, 'cart' => $cart,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $quantity): array
    {
        return [
            'productType' => 'physical', 'reference' => 'SKU-A', 'name' => 'Item', 'quantity' => $quantity,
            'unitPrice' => 2560, 'taxRate' => 2500, 'unitPriceIncVat' => 3200,
            'totalAmount' => 3200 * $quantity, 'totalVatAmount' => 640 * $quantity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function discount(int $total, int $vat): array
    {
        return [
            'productType' => 'discount', 'reference' => 'SKU-A_discount', 'name' => 'Discount', 'quantity' => 1,
            'unitPrice' => $total - $vat, 'taxRate' => 2500, 'unitPriceIncVat' => $total,
            'totalAmount' => $total, 'totalVatAmount' => $vat,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipping(): array
    {
        return [
            'productType' => 'shipping_fee', 'reference' => 'shipping', 'name' => 'Shipping', 'quantity' => 1,
            'unitPrice' => 3920, 'taxRate' => 2500, 'unitPriceIncVat' => 4900,
            'totalAmount' => 4900, 'totalVatAmount' => 980,
        ];
    }
}
