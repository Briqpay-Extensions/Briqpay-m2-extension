<?php
/**
 * One SKU on two cart lines at different prices (a custom option with a surcharge):
 * each line gets its own reference, and an invoice of both captures each line at its
 * own unit price.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Model\OrderManagement\CaptureCartBuilder;
use Briqpay\Payments\Model\OrderManagement\CaptureOrder;
use Briqpay\Payments\Model\OrderManagement\CartPreflight;
use Briqpay\Payments\Model\OrderManagement\LinePortion;
use Briqpay\Payments\Model\OrderManagement\SessionLedger;
use Briqpay\Payments\Test\Unit\Fixture\CheckoutCart;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\GenerateCart
 * @covers \Briqpay\Payments\Model\OrderManagement\CaptureOrder::itemReference
 * @covers \Briqpay\Payments\Model\OrderManagement\CaptureOrder::ledgerItems
 * @uses \Briqpay\Payments\Model\OrderManagement\CaptureCartBuilder
 * @uses \Briqpay\Payments\Model\OrderManagement\CartPreflight
 * @uses \Briqpay\Payments\Model\OrderManagement\LinePortion
 * @uses \Briqpay\Payments\Model\OrderManagement\SessionLedger
 * @uses \Briqpay\Payments\Model\Utility\Money\CartBalancer
 * @uses \Briqpay\Payments\Model\Utility\Money\CartLine
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 * @uses \Briqpay\Payments\Model\Utility\Money\VatSplit
 */
class RepeatedSkuTest extends TestCase
{
    public function testEachLineOfARepeatedSkuIsCapturedAtItsOwnUnitPrice(): void
    {
        $cart = CheckoutCart::cartData($this, [
            'items' => [
                self::item(11, '50.0000', '40.0000', '10.0000'),
                self::item(12, '60.0000', '48.0000', '12.0000'),
                ['item_id' => 13, 'sku' => '24-WB04', 'name' => 'Bag', 'qty' => 1, 'row_total_incl_tax' => '45.0000',
                    'row_total' => '36.0000', 'tax_percent' => '25.0000', 'tax_amount' => '9.0000',
                    'discount_amount' => '0.0000', 'discount_tax_compensation_amount' => '0.0000',
                    'product_type' => 'simple', 'is_virtual' => 0],
            ],
            'grand_total' => '155.0000',
            'weee' => [],
        ]);
        $references = array_column($cart['cart'], 'reference');
        $this->assertSame(['MJ01-S-Red#11', 'MJ01-S-Red#12', '24-WB04'], $references);

        $ledger = new SessionLedger(['data' => ['order' => [
            'cart' => $cart['cart'],
            'amountIncVat' => $cart['amountIncVat'],
            'amountExVat' => $cart['amountExVat'],
        ]]]);
        $items = CaptureOrder::ledgerItems($ledger, [
            new DataObject(['sku' => 'MJ01-S-Red', 'quote_item_id' => 11, 'qty' => 1]),
            new DataObject(['sku' => 'MJ01-S-Red', 'quote_item_id' => 12, 'qty' => 1]),
            new DataObject(['sku' => '24-WB04', 'quote_item_id' => 13, 'qty' => 1]),
        ]);
        $capture = (new CaptureCartBuilder(new LinePortion()))->build($ledger, $items);
        (new CartPreflight())->checkCapture($ledger, $capture);

        $this->assertSame(
            [['MJ01-S-Red#11', 4000], ['MJ01-S-Red#12', 4800], ['24-WB04', 3600]],
            array_map(static function (array $line): array {
                return [$line['reference'], $line['unitPrice']];
            }, $capture)
        );
    }

    public function testOrderOfASessionWithoutOwnReferencesKeepsTheSku(): void
    {
        $ledger = new SessionLedger(['data' => ['order' => ['cart' => [
            ['productType' => 'physical', 'reference' => 'MJ01-S-Red', 'quantity' => 2],
        ]]]]);

        $this->assertSame('MJ01-S-Red', CaptureOrder::itemReference($ledger, 'MJ01-S-Red', 11));
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(int $itemId, string $rowInclTax, string $rowTotal, string $tax): array
    {
        return ['item_id' => $itemId, 'sku' => 'MJ01-S-Red', 'name' => 'Jacket', 'qty' => 1,
            'row_total_incl_tax' => $rowInclTax, 'row_total' => $rowTotal, 'tax_percent' => '25.0000',
            'tax_amount' => $tax, 'discount_amount' => '0.0000', 'discount_tax_compensation_amount' => '0.0000',
            'product_type' => 'simple', 'is_virtual' => 0];
    }
}
