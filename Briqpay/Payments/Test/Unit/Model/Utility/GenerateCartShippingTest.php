<?php
/**
 * The shipping line: none for a cart with nothing to ship, and a tax rate Magento uses
 * although the quote stores none.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Test\Unit\Fixture\CheckoutCart;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\GenerateCart
 * @uses \Briqpay\Payments\Model\Utility\Money\CartBalancer
 * @uses \Briqpay\Payments\Model\Utility\Money\CartLine
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 * @uses \Briqpay\Payments\Model\Utility\Money\VatSplit
 */
class GenerateCartShippingTest extends TestCase
{
    /**
     * A virtual quote's shipping address can still hold a shipping amount, with no
     * description; Magento charges none.
     */
    public function testVirtualCartHasNoShippingLine(): void
    {
        $cart = CheckoutCart::cartData($this, [
            'items' => [self::item('SKU-VIRTUAL', '25.0000', '20.0000', '5.0000', '25.0000', 1)],
            'shipping' => ['shipping_amount' => '39.2000', 'shipping_incl_tax' => '49.0000',
                'shipping_tax_amount' => '9.8000', 'shipping_description' => null],
            'is_virtual' => 1,
            'grand_total' => '25.0000',
            'weee' => [],
        ]);

        $this->assertSame(['SKU-VIRTUAL'], array_column($cart['cart'], 'reference'));
        $this->assertSame(2500, $cart['amountIncVat']);
    }

    /**
     * EUR shipping 4.90 with VAT 0.79: the amounts give 19.17 %, the items' 19 % fits.
     */
    public function testShippingTakesTheItemRateItsVatFits(): void
    {
        $cart = CheckoutCart::cartData($this, [
            'items' => [self::item('24-WB04', '45.0000', '37.8200', '7.1800', '19.0000')],
            'shipping' => ['shipping_amount' => '4.1200', 'shipping_incl_tax' => '4.9000',
                'shipping_tax_amount' => '0.7900', 'shipping_description' => 'Flat Rate - Fixed'],
            'grand_total' => '49.9000',
            'weee' => [],
        ]);

        $shipping = $cart['cart'][1];
        $this->assertSame(
            [1900, 490, 79],
            [$shipping['taxRate'], $shipping['totalAmount'], $shipping['totalVatAmount']]
        );
        $this->assertSame(4990, $cart['amountIncVat']);
    }

    /**
     * Shipping whose VAT fits no item rate keeps the rate its amounts give.
     */
    public function testShippingKeepsItsOwnRateWhenNoItemRateFits(): void
    {
        $cart = CheckoutCart::cartData($this, [
            'items' => [self::item('24-WB04', '45.0000', '40.1800', '4.8200', '12.0000')],
            'shipping' => ['shipping_amount' => '39.2000', 'shipping_incl_tax' => '49.0000',
                'shipping_tax_amount' => '9.8000', 'shipping_description' => 'Flat Rate - Fixed'],
            'grand_total' => '94.0000',
            'weee' => [],
        ]);

        $this->assertSame(2500, $cart['cart'][1]['taxRate']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(
        string $sku,
        string $rowTotalInclTax,
        string $rowTotal,
        string $taxAmount,
        string $taxPercent,
        int $isVirtual = 0
    ): array {
        return [
            'item_id' => 1, 'sku' => $sku, 'name' => $sku, 'qty' => 1,
            'row_total_incl_tax' => $rowTotalInclTax, 'row_total' => $rowTotal, 'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount, 'discount_amount' => '0.0000', 'discount_tax_compensation_amount' => '0.0000',
            'product_type' => $isVirtual ? 'virtual' : 'simple', 'is_virtual' => $isVirtual,
        ];
    }
}
