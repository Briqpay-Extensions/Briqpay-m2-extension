<?php
/**
 * Pin the money rules of MinorUnits. A failure here is a
 * regression in money handling, never a reason to update the expectation.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility\Money;

use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\Money\MinorUnits
 */
class MinorUnitsTest extends TestCase
{
    /**
     * @dataProvider fromFloatProvider
     */
    public function testFromFloat(mixed $input, int $expected): void
    {
        $this->assertSame($expected, MinorUnits::fromFloat($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public function fromFloatProvider(): array
    {
        return [
            'docblock example' => [94.38, 9438],
            'string from the database' => ['364.9600', 36496],
            'binary-unfriendly sum' => [0.1 + 0.2, 30],
            'half a cent stored just below .5' => [1.005, 101],
            'another half a cent stored just below .5' => [0.285, 29],
            'negative half a cent stored just below .5' => [-1.005, -101],
            'just under half a cent' => [1.0049999999, 100],
            'negative' => [-25.0, -2500],
            'zero' => [0, 0],
            'null' => [null, 0],
        ];
    }

    /**
     * @dataProvider roundProvider
     */
    public function testRoundIsHalfAwayFromZeroOnBothSides(float $input, int $expected): void
    {
        $this->assertSame($expected, MinorUnits::round($input));
    }

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public function roundProvider(): array
    {
        return [
            'positive half' => [2.5, 3],
            'negative half' => [-2.5, -3],
            'below half' => [2.4999, 2],
            'negative below half' => [-2.4999, -2],
        ];
    }

    /**
     * @dataProvider divRoundProvider
     */
    public function testDivRound(int|float $numerator, int|float $denominator, int $expected): void
    {
        $this->assertSame($expected, MinorUnits::divRound($numerator, $denominator));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: int}>
     */
    public function divRoundProvider(): array
    {
        return [
            'exact' => [471900, 50, 9438],
            'fractional quantity' => [36496, 4.3, 8487],
            'half rounds away from zero' => [47190, 4, 11798],
            'negative half rounds away from zero' => [-5, 2, -3],
            'zero denominator returns zero' => [100, 0, 0],
            'float zero denominator returns zero' => [100, 0.0, 0],
        ];
    }
}
