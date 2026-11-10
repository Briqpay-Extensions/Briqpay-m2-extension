<?php

declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility\Money;

use Briqpay\Payments\Model\Utility\Money\VatSplit;
use PHPUnit\Framework\TestCase;

/**
 * A discounted row's VAT is shared between its item and discount lines so each line's VAT
 * matches its rate, with the row's VAT unchanged.
 *
 * @covers \Briqpay\Payments\Model\Utility\Money\VatSplit
 */
class VatSplitTest extends TestCase
{
    /**
     * @dataProvider splitProvider
     * @param array<int, int> $input gross incl. VAT, gross VAT, discount incl. VAT, discount VAT, rate, quantity
     * @param array{grossVat: int, discountVat: int} $expected
     */
    public function testSplit(array $input, array $expected): void
    {
        [$grossIncl, $grossVat, $discountIncl, $discountVat, $rate] = $input;
        $quantity = $input[5] ?? 1;
        $split = VatSplit::split($grossIncl, $grossVat, $discountIncl, $discountVat, $rate, $quantity);

        $this->assertSame($expected, $split);
        $this->assertSame($grossVat - $discountVat, $split['grossVat'] - $split['discountVat'], 'row VAT unchanged');
        if ($split !== ['grossVat' => $grossVat, 'discountVat' => $discountVat]) {
            $this->assertTrue(VatSplit::fits($grossIncl, $split['grossVat'], $rate), 'item line fits');
            $this->assertTrue(VatSplit::fits($discountIncl, $split['discountVat'], $rate), 'discount line fits');
        }
    }

    /**
     * @return array<string, array{array<int, int>, array{grossVat: int, discountVat: int}}>
     */
    public function splitProvider(): array
    {
        return [
            'WB04 -6.52 discount at 19 %' => [
                [4500, 718, 652, 103, 1900],
                ['grossVat' => 719, 'discountVat' => 104],
            ],
            'WB07 x1 -10.00 discount at 19 %' => [
                [8100, 1294, 1000, 161, 1900],
                ['grossVat' => 1293, 'discountVat' => 160],
            ],
            'WB07 x2 at 19 %: both lines fit, the unit price need not divide' => [
                [8100, 1294, 1000, 161, 1900, 2],
                ['grossVat' => 1293, 'discountVat' => 160],
            ],
            'WB07 -5.00 discount at 19 %' => [
                [4050, 647, 500, 81, 1900],
                ['grossVat' => 646, 'discountVat' => 80],
            ],
            'already fits: unchanged' => [
                [4500, 718, 500, 79, 1900],
                ['grossVat' => 718, 'discountVat' => 79],
            ],
            'already fits at 25 %: unchanged' => [
                [4500, 900, 652, 130, 2500],
                ['grossVat' => 900, 'discountVat' => 130],
            ],
        ];
    }

    public function testDiscountAfterTaxKeepsNoVat(): void
    {
        $this->assertSame(['grossVat' => 718, 'discountVat' => 0], VatSplit::split(4500, 718, 500, 0, 1900));
    }

    /**
     * The second of two discounted MB04 at 19 %, invoiced with WB07: the discount line's VAT
     * (-0.46 on -2.80) cannot fit on its own, so a cent goes to another line of the rate.
     */
    public function testFitLinesSharesVatWithAnotherLineOfTheRate(): void
    {
        $lines = [
            self::line('physical', 2800, 448, 1900),
            self::line('discount', -280, -46, 1900),
            self::line('physical', 4050, 646, 1900),
            self::line('discount', -405, -64, 1900),
            self::line('shipping_fee', 490, 0, 0),
        ];

        $fitted = VatSplit::fitLines($lines);

        $this->assertSame([447, -45, 646, -64, 0], array_column($fitted, 'totalVatAmount'));
        $this->assertSame(array_column($lines, 'totalAmount'), array_column($fitted, 'totalAmount'));
        $this->assertSame(-235, $fitted[1]['unitPrice'], 'the discount line\'s unit price follows its VAT');
        $this->assertSame(2352, $fitted[0]['unitPrice'], 'an item line keeps its unit price');
        foreach ($fitted as $line) {
            $this->assertTrue(VatSplit::fits($line['totalAmount'], $line['totalVatAmount'], $line['taxRate']));
        }
    }

    /**
     * A credit memo whose own VAT no split can fit (39.99 with VAT 6.36 at 19 %): the lines
     * keep their VAT.
     */
    public function testFitLinesLeavesLinesThatCannotFit(): void
    {
        $lines = [
            self::line('physical', 4500, 718, 1900),
            self::line('discount', -500, -82, 1900),
            self::line('adjustment', -1, 0, 0),
        ];

        $this->assertSame($lines, VatSplit::fitLines($lines));
    }

    /**
     * What no sharing can fit is moved to the nearest VAT the rate allows; a 0 % line
     * carries none. Amounts stay.
     */
    public function testForceFitMovesEachLineToTheNearestVatItsRateAllows(): void
    {
        $lines = [
            self::line('physical', 4500, 718, 1900),
            self::line('discount', -500, -82, 1900),
            self::line('discount', -300, 1, 0),
        ];

        $fitted = VatSplit::forceFit($lines);

        $this->assertSame([718, -80, 0], array_column($fitted, 'totalVatAmount'));
        $this->assertSame(array_column($lines, 'totalAmount'), array_column($fitted, 'totalAmount'));
        $this->assertSame(-420, $fitted[1]['unitPrice']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(string $type, int $total, int $vat, int $rate): array
    {
        return [
            'productType' => $type,
            'quantity' => 1,
            'unitPrice' => $type === 'physical' ? 2352 : $total - $vat,
            'totalAmount' => $total,
            'totalVatAmount' => $vat,
            'taxRate' => $rate,
        ];
    }

    public function testFits(): void
    {
        $this->assertFalse(VatSplit::fits(-652, -103, 1900));
        $this->assertTrue(VatSplit::fits(-652, -104, 1900));
        $this->assertFalse(VatSplit::fits(-652, -129, 2500));
        $this->assertTrue(VatSplit::fits(-2, 0, 0));
        $this->assertFalse(VatSplit::fits(-2, -1, 0));
    }
}
