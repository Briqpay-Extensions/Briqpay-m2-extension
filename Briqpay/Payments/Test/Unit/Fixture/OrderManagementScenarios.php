<?php
/**
 * Cart shapes for the order-management tests, on top of Scenarios: the awkward ones
 * for capture and refund. All data is synthetic; amounts are what
 * Magento stores on quote and order items.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Fixture;

class OrderManagementScenarios
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return Scenarios::all() + self::discounted() + [
            'three_units_that_do_not_divide' => [
                'items' => [self::item(201, 'SKU-C', 'Three for a hundred', 3, '100.0000', '25.0000', '20.0000')],
                'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
                'grand_total' => '149.0000',
                'weee' => [],
            ],
            'mixed_tax_rates' => [
                'items' => [
                    self::item(211, 'SKU-STD', 'Standard rate', 1, '199.0000', '25.0000', '39.8000'),
                    self::item(212, 'SKU-FOOD', 'Food', 2, '59.8000', '12.0000', '6.4100'),
                    self::item(213, 'SKU-BOOK', 'Book', 1, '249.0000', '6.0000', '14.0900'),
                ],
                'shipping' => self::shipping('47.2000', '59.0000', '11.8000'),
                'grand_total' => '566.8000',
                'weee' => [],
            ],
            'coupon_on_two_items' => [
                'items' => [
                    // Discounts on prices incl. 25 % VAT, with the tax and compensation
                    // Magento stores for them.
                    self::item(221, 'SKU-A', 'Product A', 2, '250.0000', '25.0000', '46.0000', '20.0000', '4.0000'),
                    self::item(222, 'SKU-B', 'Product B', 1, '99.9000', '25.0000', '18.3800', '7.9900', '1.6000'),
                ],
                'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
                'grand_total' => '370.9100',
                'weee' => [],
            ],
            'free_shipping' => [
                'items' => [self::item(231, 'SKU-A', 'Product A', 2, '250.0000', '25.0000', '50.0000')],
                'shipping' => null,
                'grand_total' => '250.0000',
                'weee' => [],
            ],
            'virtual_item' => [
                'items' => [
                    self::item(241, 'SKU-A', 'Product A', 1, '125.0000', '25.0000', '25.0000'),
                    self::item(
                        242,
                        'SKU-GIFT',
                        'Digital gift',
                        1,
                        '200.0000',
                        '25.0000',
                        '40.0000',
                        '0.0000',
                        '0.0000',
                        'virtual'
                    ),
                ],
                'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
                'grand_total' => '374.0000',
                'weee' => [],
            ],
            // Stored by Magento 2.4.8 for a 10 % coupon with prices and discounts incl.
            // 25 % VAT: discount_amount is already incl. VAT, its VAT sits in the
            // compensation field.
            'coupon_on_prices_incl_vat' => [
                'items' => [
                    [
                        'item_id' => 261, 'sku' => '24-MB04', 'name' => 'Strive Shoulder Pack', 'qty' => 2,
                        'row_total_incl_tax' => '64.0000', 'row_total' => '51.2000', 'tax_percent' => '25.0000',
                        'tax_amount' => '11.5200', 'discount_amount' => '6.4000',
                        'discount_tax_compensation_amount' => '1.2800', 'product_type' => 'simple', 'is_virtual' => 0,
                    ],
                    [
                        'item_id' => 262, 'sku' => '24-WB06', 'name' => 'Endeavor Daytrip Backpack', 'qty' => 3,
                        'row_total_incl_tax' => '99.0000', 'row_total' => '79.2000', 'tax_percent' => '25.0000',
                        'tax_amount' => '17.8200', 'discount_amount' => '9.9000',
                        'discount_tax_compensation_amount' => '1.9800', 'product_type' => 'simple', 'is_virtual' => 0,
                    ],
                ],
                'shipping' => [
                    'shipping_amount' => '39.2000',
                    'shipping_incl_tax' => '49.0000',
                    'shipping_tax_amount' => '9.8000',
                    'shipping_description' => 'Flat Rate - Fixed',
                ],
                'grand_total' => '195.7000',
                'weee' => [],
            ],
            'fifty_units' => [
                'items' => [self::item(251, 'SKU-BULK', 'Bulk', 50, '4719.0000', '25.0000', '943.8000')],
                'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
                'grand_total' => '4768.0000',
                'weee' => [],
            ],
        ];
    }

    /**
     * Discount shapes where Magento's own split of shipping, tax and discount matters.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function discounted(): array
    {
        return [
            // Stored by Magento 2.4.8 for a 10 % coupon that also applies to shipping,
            // prices and discounts incl. 25 % VAT: the shipping discount and its VAT sit
            // on the shipping address, and the shipping tax is on the discounted amount.
            'shipping_discount_incl_vat' => [
                'items' => [
                    self::row(
                        271,
                        '24-WB04',
                        'Push It Messenger Bag',
                        1,
                        '45.0000',
                        '36.0000',
                        '8.1000',
                        '4.5000',
                        '0.9000'
                    ),
                    self::row(
                        272,
                        '24-MB04',
                        'Strive Shoulder Pack',
                        1,
                        '32.0000',
                        '25.6000',
                        '5.7600',
                        '3.2000',
                        '0.6400'
                    ),
                ],
                'shipping' => [
                    'shipping_amount' => '39.2000',
                    'shipping_incl_tax' => '49.0000',
                    'shipping_tax_amount' => '8.8200',
                    'shipping_discount_amount' => '4.9000',
                    'shipping_discount_tax_compensation_amount' => '0.9800',
                    'shipping_description' => 'Flat Rate - Fixed',
                ],
                'grand_total' => '113.4000',
                'weee' => [],
            ],
            // Stored by Magento 2.4.8 for a fixed 20.00 cart discount spread over three
            // lines, one of them tax exempt, prices and discounts incl. 25 % VAT.
            'fixed_cart_discount_over_lines' => [
                'items' => [
                    self::row(
                        281,
                        '24-WB04',
                        'Push It Messenger Bag',
                        1,
                        '45.0000',
                        '36.0000',
                        '7.7400',
                        '6.2900',
                        '1.2600'
                    ),
                    self::row(
                        282,
                        '24-MB04',
                        'Strive Shoulder Pack',
                        2,
                        '64.0000',
                        '51.2000',
                        '11.0100',
                        '8.9500',
                        '1.7900'
                    ),
                    self::row(
                        283,
                        '24-MB01',
                        'Joust Duffle Bag',
                        1,
                        '34.0000',
                        '34.0000',
                        '0.0000',
                        '4.7600',
                        '0.0000',
                        '0.0000'
                    ),
                ],
                'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
                'grand_total' => '172.0000',
                'weee' => [],
            ],
        ];
    }

    /**
     * A bundle priced from its children (dynamic price): two bundles, each one A at
     * 25 % VAT and two B at 6 % VAT. The bundle line carries the children's sums and
     * no tax rate of its own.
     *
     * @return array<string, mixed>
     */
    public static function dynamicBundle(): array
    {
        return [
            'items' => [
                [
                    'item_id' => 291, 'sku' => 'BUNDLE-A-B', 'name' => 'Starter kit', 'qty' => 2,
                    'row_total_incl_tax' => '412.0000', 'row_total' => '360.0000', 'tax_percent' => null,
                    'tax_amount' => '52.0000', 'discount_amount' => '0.0000',
                    'discount_tax_compensation_amount' => '0.0000', 'product_type' => 'bundle', 'is_virtual' => 0,
                    'children_calculated' => true,
                    'children' => [
                        self::row(292, 'KIT-A', 'Kit part A', 1, '200.0000', '160.0000', '40.0000', '0.0000', '0.0000'),
                        self::row(
                            293,
                            'KIT-B',
                            'Kit part B',
                            2,
                            '212.0000',
                            '200.0000',
                            '12.0000',
                            '0.0000',
                            '0.0000',
                            '6.0000'
                        ),
                    ],
                ],
            ],
            'shipping' => self::shipping('39.2000', '49.0000', '9.8000'),
            'grand_total' => '461.0000',
            'weee' => [],
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
     * @param int|float $qty
     * @return array<string, mixed>
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
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
        string $discountTaxCompensationAmount = '0.0000',
        string $productType = 'simple'
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
            'product_type' => $productType,
            'is_virtual' => $productType === 'virtual' ? 1 : 0,
        ];
    }

    /**
     * An item with every amount Magento stores on it, row total excl. VAT included.
     *
     * @param int|float $qty
     * @return array<string, mixed>
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    private static function row(
        int $itemId,
        string $sku,
        string $name,
        $qty,
        string $rowTotalInclTax,
        string $rowTotal,
        string $taxAmount,
        string $discountAmount,
        string $discountTaxCompensationAmount,
        string $taxPercent = '25.0000'
    ): array {
        return [
            'item_id' => $itemId,
            'sku' => $sku,
            'name' => $name,
            'qty' => $qty,
            'row_total_incl_tax' => $rowTotalInclTax,
            'row_total' => $rowTotal,
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
