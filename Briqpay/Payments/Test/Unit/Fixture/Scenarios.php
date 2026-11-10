<?php
/**
 * Cart shapes shared by the tests, so each document is built from the same order. All
 * data is synthetic.
 *
 * Amounts are what Magento stores on quote and order items, as decimal strings, the
 * way they come back from the database.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Fixture;

class Scenarios
{
    public const SESSION_ID = 'test-session-0001';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'two_items_with_shipping' => self::twoItemsWithShipping(),
            'discount_without_tax_compensation' => self::discountWithoutTaxCompensation(),
            'discount_with_tax_compensation' => self::discountWithTaxCompensation(),
            'weee_untaxed' => self::weeeUntaxed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $name): array
    {
        return self::all()[$name];
    }

    /**
     * @return array<string, mixed>
     */
    private static function twoItemsWithShipping(): array
    {
        return [
            'items' => [
                self::item(101, 'SKU-A', 'Product A', 2, '250.0000', '25.0000', '50.0000'),
                self::item(102, 'SKU-B', 'Product B', 1, '99.9000', '25.0000', '19.9800'),
            ],
            'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
            'grand_total' => '398.9000',
            'weee' => [],
        ];
    }

    /**
     * Discount stored excl. VAT, with the VAT reduction only visible as the lower tax
     * amount: 250.00 incl. VAT less 20.00 ex-VAT discount, tax recalculated to 45.00.
     *
     * @return array<string, mixed>
     */
    private static function discountWithoutTaxCompensation(): array
    {
        return [
            'items' => [
                self::item(101, 'SKU-A', 'Product A', 2, '250.0000', '25.0000', '45.0000', '20.0000', '0.0000'),
            ],
            'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
            'grand_total' => '274.0000',
            'weee' => [],
        ];
    }

    /**
     * Same order with the VAT part of the discount carried in
     * discount_tax_compensation_amount.
     *
     * @return array<string, mixed>
     */
    private static function discountWithTaxCompensation(): array
    {
        return [
            'items' => [
                // 20.00 off prices incl. 25 % VAT, as Magento stores it: the discount's VAT
                // (4.00) in the compensation field, the tax on the discounted row (46.00).
                self::item(101, 'SKU-A', 'Product A', 2, '250.0000', '25.0000', '46.0000', '20.0000', '4.0000'),
            ],
            'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
            'grand_total' => '279.0000',
            'weee' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function weeeUntaxed(): array
    {
        return [
            'items' => [
                self::item(101, 'SKU-TV', 'Television', 1, '1250.0000', '25.0000', '250.0000'),
            ],
            'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
            'grand_total' => '1304.0000',
            'weee' => ['SKU-TV' => '5.0000'],
        ];
    }

    /**
     * @param int|float $qty
     * @return array<string, mixed>
     */
    private static function item(
        int $itemId,
        string $sku,
        string $name,
        $qty,
        string $rowTotalInclTax,
        string $taxPercent,
        string $taxAmount,
        string $discountAmount = '0.0000',
        string $discountTaxCompensationAmount = '0.0000'
    ): array {
        return [
            'item_id' => $itemId,
            'sku' => $sku,
            'name' => $name,
            'qty' => $qty,
            'row_total_incl_tax' => $rowTotalInclTax,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'discount_tax_compensation_amount' => $discountTaxCompensationAmount,
            'product_type' => 'simple',
            'is_virtual' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function shipping(string $amount, string $inclTax, string $tax): array
    {
        return [
            'shipping_amount' => $amount,
            'shipping_incl_tax' => $inclTax,
            'shipping_tax_amount' => $tax,
            'shipping_description' => 'Flat Rate - Fixed',
        ];
    }
}
