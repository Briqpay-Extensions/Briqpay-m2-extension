<?php
/**
 * The discount line checkout sends for each item, on amounts Magento itself stored:
 * the real discount incl. VAT, whether Magento applies discounts to prices incl. or
 * excl. VAT, and no rounding line to make up a difference.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Test\Unit\Fixture\CheckoutCart;
use Briqpay\Payments\Test\Unit\Fixture\OrderManagementScenarios;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\GenerateCart
 * @uses \Briqpay\Payments\Model\Utility\Money\CartBalancer
 * @uses \Briqpay\Payments\Model\Utility\Money\CartLine
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 */
class GenerateCartDiscountTest extends TestCase
{
    public function testDiscountAppliedInclVatIsSentAtItsRealAmountWithoutARoundingLine(): void
    {
        $cart = CheckoutCart::cartData($this, OrderManagementScenarios::get('coupon_on_prices_incl_vat'));

        $totals = [];
        foreach ($cart['cart'] as $line) {
            $totals[$line['reference']] = $line['totalAmount'];
        }

        $this->assertSame([
            '24-MB04' => 6400,
            '24-MB04_discount' => -640,
            '24-WB06' => 9900,
            '24-WB06_discount' => -990,
            'shipping' => 4900,
        ], $totals);
        $this->assertSame(19570, $cart['amountIncVat']);
    }

    public function testDiscountAppliedExclVatGetsItsVatAdded(): void
    {
        // The same order with the discount applied excl. VAT: 6.40 off 51.20 net, so
        // 8.00 off incl. VAT.
        $scenario = OrderManagementScenarios::get('coupon_on_prices_incl_vat');
        $scenario['items'] = [array_merge($scenario['items'][0], [
            'row_total_incl_tax' => '64.0000',
            'row_total' => '51.2000',
            'tax_amount' => '11.2000',
            'discount_amount' => '6.4000',
            'discount_tax_compensation_amount' => '0.0000',
        ])];
        $scenario['grand_total'] = '105.0000';

        $cart = CheckoutCart::cartData($this, $scenario);

        $this->assertSame(-800, $cart['cart'][1]['totalAmount']);
        $this->assertCount(3, $cart['cart']);
    }

    /**
     * With tax applied before the discount, Magento charges the undiscounted row's tax,
     * so the discount takes no VAT off and its line carries 0 % VAT.
     */
    public function testDiscountAfterTaxCarriesNoVat(): void
    {
        $cart = CheckoutCart::cartData($this, [
            'items' => [[
                'item_id' => 301, 'sku' => '24-MB04', 'name' => 'Strive Shoulder Pack', 'qty' => 2,
                'row_total_incl_tax' => '64.0000', 'row_total' => '51.2000', 'tax_percent' => '25.0000',
                'tax_amount' => '12.8000', 'discount_amount' => '6.4000',
                'discount_tax_compensation_amount' => '0.0000', 'product_type' => 'simple', 'is_virtual' => 0,
            ]],
            'shipping' => null,
            'grand_total' => '57.6000',
            'weee' => [],
        ]);

        $lines = [];
        foreach ($cart['cart'] as $line) {
            $lines[$line['reference']] = [$line['totalAmount'], $line['taxRate'], $line['totalVatAmount']];
        }

        $this->assertSame([
            '24-MB04' => [6400, 2500, 1280],
            '24-MB04_discount' => [-640, 0, 0],
        ], $lines);
        $this->assertSame(5760, $cart['amountIncVat']);
    }
}
