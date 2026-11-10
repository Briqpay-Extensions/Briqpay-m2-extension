<?php
/**
 * An invoice before the last whose discount, as Magento splits it in proportion, is a
 * minor unit more than the session's discount line has left: the discount line takes
 * what is left, the item line the rest, so the capture is still the invoice's amount.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\OrderManagement;

use Briqpay\Payments\Model\OrderManagement\CaptureCartBuilder;
use Briqpay\Payments\Model\OrderManagement\CaptureOrder;
use Briqpay\Payments\Model\OrderManagement\CartPreflight;
use Briqpay\Payments\Model\OrderManagement\LinePortion;
use Briqpay\Payments\Model\OrderManagement\SessionLedger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\OrderManagement\CaptureCartBuilder
 * @uses \Briqpay\Payments\Model\OrderManagement\CaptureOrder
 * @uses \Briqpay\Payments\Model\OrderManagement\CartPreflight
 * @uses \Briqpay\Payments\Model\OrderManagement\LinePortion
 * @uses \Briqpay\Payments\Model\OrderManagement\SessionLedger
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 * @uses \Briqpay\Payments\Model\Utility\Money\VatSplit
 */
class CaptureDiscountLeftTest extends TestCase
{
    public function testDiscountBeyondWhatIsLeftGoesOnTheItemLine(): void
    {
        $item = ['productType' => 'physical', 'reference' => 'X', 'name' => 'X', 'quantity' => 3,
            'quantityUnit' => 'pc', 'unitPrice' => 800, 'unitPriceIncVat' => 1000, 'taxRate' => 2500,
            'totalAmount' => 3000, 'totalVatAmount' => 600];
        $discount = ['productType' => 'discount', 'reference' => 'X_discount', 'name' => 'Discount for X',
            'quantity' => 1, 'quantityUnit' => 'pc', 'unitPrice' => -300, 'unitPriceIncVat' => -375,
            'taxRate' => 2500, 'totalAmount' => -375, 'totalVatAmount' => -75];
        $ledger = new SessionLedger([
            'references' => [SessionLedger::LINE_AMOUNTS_REFERENCE => SessionLedger::LINE_AMOUNTS_MAGENTO],
            'data' => [
                'order' => ['cart' => [$item, $discount], 'amountIncVat' => 2625, 'amountExVat' => 2100],
                'captures' => [['captureId' => 'c1', 'status' => 'approved', 'cart' => [
                    ['quantity' => 1, 'totalAmount' => 1000, 'totalVatAmount' => 200] + $item,
                    ['totalAmount' => -250, 'totalVatAmount' => -50, 'unitPrice' => -200] + $discount,
                ], 'amountIncVat' => 750, 'amountExVat' => 600]],
            ],
        ]);

        // The first capture took -2.50 of the -3.75 discount, leaving -1.25; Magento gives
        // the next invoice (one unit, not the last) -1.26. Without the limit the preflight
        // would refuse it.
        $cart = (new CaptureCartBuilder(new LinePortion()))->build($ledger, [[
            'reference' => 'X',
            'quantity' => 1.0,
            'document' => ['grossInclVat' => 1000, 'grossVat' => 200, 'discountInclVat' => 126, 'discountVat' => 25],
        ]]);
        (new CartPreflight())->checkCapture($ledger, $cart);

        $this->assertSame(874, CaptureOrder::orderTotals($cart)['amountIncVat']);
        $this->assertSame(-125, $cart[1]['totalAmount'], 'the discount line takes what is left');
        $this->assertSame(999, $cart[0]['totalAmount'], 'the item line takes the minor unit');
    }
}
