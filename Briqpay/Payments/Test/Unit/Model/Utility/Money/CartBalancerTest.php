<?php
/**
 * Pin the money rules of CartBalancer::balance() for both
 * the checkout mode (allowFold = true) and the capture/refund mode (allowFold = false).
 * The reasons behind every rule are in CartBalancer's docblock. A failure here is a
 * regression in money handling, never a reason to update the expectation.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility\Money;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\Money\CartBalancer;
use Briqpay\Payments\Model\Utility\Money\CartLine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\Money\CartBalancer
 * @uses \Briqpay\Payments\Model\Utility\Money\CartLine
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 */
class CartBalancerTest extends TestCase
{
    /**
     * @var Logger&MockObject
     */
    private $logger;

    /**
     * @var CartLine
     */
    private CartLine $cartLine;

    /**
     * @var CartBalancer
     */
    private CartBalancer $balancer;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->cartLine = new CartLine();
        $this->balancer = new CartBalancer($this->logger, $this->cartLine);
    }

    public function testCheckoutCartThatAlreadyReconcilesIsReturnedUnchanged(): void
    {
        $lines = [
            $this->item(25000, 2),
            $this->shipping(4900),
        ];
        $this->logger->expects($this->never())->method('error');

        $result = $this->balancer->balance($lines, 29900);

        $this->assertSame($lines, $result['cart']);
        $this->assertSame(29900, $result['amountIncVat']);
        $this->assertSame(10000 * 2 + 3920, $result['amountExVat']);
    }

    /**
     * A difference is an amount Magento charges that no line models: the cart is refused
     * with the amounts and the Magento totals in the message.
     *
     * @dataProvider differenceProvider
     */
    public function testCheckoutCartThatDoesNotAddUpIsRefusedNamingTheDifference(int $difference): void
    {
        $lines = [$this->item(25000, 2), $this->shipping(4900)];
        $this->logger->expects($this->once())->method('error')
            ->with(
                $this->stringContains('No session was created'),
                $this->callback(static function (array $c) use ($difference) {
                    return $c['difference'] === $difference && $c['magentoTotals'] === ['customerbalance' => -5.0];
                })
            );

        try {
            $this->balancer->balance($lines, 29900 + $difference, true, ['customerbalance' => -5.0]);
            $this->fail('A cart that does not add up must be refused.');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->assertSame(
                'The cart total (' . (29900 + $difference) . ') differs from the sum of its lines (29900) by '
                . $difference . ' minor units: a Magento total (customerbalance) has no Briqpay line. '
                . 'No session was created.',
                $e->getMessage()
            );
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function differenceProvider(): array
    {
        return ['one minor unit' => [1], 'minus one' => [-1], 'a missing total' => [-500]];
    }

    public function testNoLineIsEverNamedRounding(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 5) . '/Model/Utility/Money/CartBalancer.php'
        );

        $this->assertStringNotContainsString("'rounding'", $source);
        $this->assertStringNotContainsString('Rounding', str_replace('"Rounding" line', '', $source));
    }

    public function testCaptureModeNeverCorrectsTheCartAndUsesItsOwnSum(): void
    {
        $lines = [
            $this->item(36496, 4),
            $this->shipping(4900),
        ];
        $this->logger->expects($this->never())->method('error');

        $result = $this->balancer->balance($lines, 36496 + 4900, false);

        $this->assertSame($lines, $result['cart']);
        $this->assertSame(36496 + 4900, $result['amountIncVat']);
    }

    public function testCaptureModeLogsALargeDivergenceButStillSendsItsOwnSum(): void
    {
        $lines = [$this->item(25000, 2)];
        $this->logger->expects($this->once())->method('error');

        $result = $this->balancer->balance($lines, 30000, false);

        $this->assertSame($lines, $result['cart']);
        $this->assertSame(25000, $result['amountIncVat']);
    }

    public function testAmountExVatIsTheRoundedSumOfUnitPriceTimesQuantity(): void
    {
        $lines = [$this->item(36496, 4)];

        $result = $this->balancer->balance($lines, 36496, false);

        // 7299 * 4
        $this->assertSame(29196, $result['amountExVat']);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $rowTotalIncVat, int|float $qty): array
    {
        return $this->cartLine->build($rowTotalIncVat, $qty, 2500, 'physical', 'SKU-1', 'Item');
    }

    /**
     * @return array<string, mixed>
     */
    private function shipping(int $amount): array
    {
        return $this->cartLine->build($amount, 1, 2500, 'shipping_fee', 'shipping', 'Shipping');
    }
}
