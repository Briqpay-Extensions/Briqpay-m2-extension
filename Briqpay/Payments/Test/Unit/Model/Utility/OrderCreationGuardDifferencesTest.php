<?php
/**
 * An order is held when Briqpay was paid for another cart than the one it was made from:
 * the cart changed in another window while the payment ran.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Model\Utility\CompareData;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\OrderCreationGuard;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\OrderCreationGuard
 * @uses \Briqpay\Payments\Model\Utility\CompanyName
 * @uses \Briqpay\Payments\Model\Utility\CompareData
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 */
class OrderCreationGuardDifferencesTest extends TestCase
{
    /**
     * @var OrderCreationGuard|null a guard whose placement failed in the test
     */
    private ?OrderCreationGuard $guardAfterFailure = null;

    /**
     * Another window changed the cart while the payment ran, at the same total.
     *
     * @dataProvider cartsProvider
     * @param array<int, array<string, mixed>> $orderedLines the lines of the cart ordered
     */
    public function testOrderMadeFromAnotherCartThanBriqpayWasPaidForIsHeld(
        array $orderedLines,
        string $customerType,
        string $billingCompany,
        bool $held,
        bool $zeroShippingInSession = false
    ): void {
        $order = $this->order('100.0000', 'SEK');
        $order->method('canHold')->willReturn(true);
        $order->expects($held ? $this->once() : $this->never())->method('hold');
        $order->expects($held ? $this->once() : $this->never())->method('addCommentToStatusHistory')
            ->with($this->stringContains('differs from this order'));
        $session = self::paidSession();
        $session['references'] += ['lineAmounts' => 'magento', 'companyName' => 'Buyer AB'];
        $session['data']['order']['cart'] = [self::line('A')];
        if ($zeroShippingInSession) {
            $session['data']['order']['cart'][] = ['reference' => 'shipping', 'quantity' => 1, 'totalAmount' => 0,
                'totalVatAmount' => 0];
        }
        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress', 'getShippingAddress', 'getIsVirtual'])
            ->getMock();
        $quote->method('getBillingAddress')
            ->willReturn(new \Magento\Framework\DataObject(['company' => $billingCompany]));

        $this->guard($orderedLines, $customerType)->holdIfSessionDiffers($order, $quote, $session);
    }

    public function testAnOrderHeldForItsAmountGetsOneNote(): void
    {
        // Another amount changes the lines too; the amount's note says it all.
        $order = $this->order('2100.0000', 'SEK');
        $order->method('canHold')->willReturn(true);
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->stringContains('Briqpay authorised 100.00 SEK'));
        $session = self::paidSession();
        $session['references']['lineAmounts'] = 'magento';
        $session['data']['order']['cart'] = [self::line('A')];

        $this->guard([self::line('B')], 'consumer')
            ->holdIfSessionDiffers($order, $this->createMock(\Magento\Quote\Model\Quote::class), $session);
    }

    public function testACartThatCannotBeComparedHoldsTheOrderInsteadOfFailing(): void
    {
        // The order is saved already; it must keep its Briqpay data.
        $order = $this->order('100.0000', 'SEK');
        $order->method('canHold')->willReturn(true);
        $order->expects($this->once())->method('hold');
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->stringContains('could not be compared'));
        $session = self::paidSession();
        $session['references']['lineAmounts'] = 'magento';
        $cart = $this->createMock(GenerateCart::class);
        $cart->method('getCartDataFor')->willThrowException(new \RuntimeException('test failure'));
        $guard = new OrderCreationGuard(
            $this->createMock(LockManagerInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(Logger::class),
            $cart,
            new CompareData(),
            $this->createMock(SetupConfig::class)
        );

        $guard->holdIfSessionDiffers($order, $this->createMock(\Magento\Quote\Model\Quote::class), $session);
    }

    public function testAPlacementIsKnownWhileItRuns(): void
    {
        $guard = $this->guard([], 'consumer');

        $this->assertTrue($guard->placing(static fn () => $guard->isPlacing()));
        $this->assertFalse($guard->isPlacing());
    }

    public function testAFailedPlacementIsNoLongerKnownAfterwards(): void
    {
        $guard = $this->guard([], 'consumer');
        $this->expectException(\RuntimeException::class);
        $failing = static function () use ($guard): void {
            throw new \RuntimeException($guard->isPlacing() ? 'placing' : 'not placing');
        };

        $this->expectExceptionMessageMatches('/^placing$/');
        $this->registerFailureCheck($guard);
        $guard->placing($failing);
    }

    private function registerFailureCheck(OrderCreationGuard $guard): void
    {
        // Checked when the test ends, after the exception left placing().
        $this->guardAfterFailure = $guard;
    }

    protected function assertPostConditions(): void
    {
        if ($this->guardAfterFailure !== null) {
            $this->assertFalse($this->guardAfterFailure->isPlacing());
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function cartsProvider(): array
    {
        return [
            'the same cart' => [[self::line('A')], 'business', 'Buyer AB', false],
            'another line at the same total' => [[self::line('B')], 'consumer', '', true],
            'another company' => [[self::line('A')], 'business', 'Other AB', true],
            'a company in a consumer shop' => [[self::line('A')], 'consumer', 'Other AB', false],
            // Magento reports free shipping as "0.0000" before its totals are collected
            // (a zero shipping line is sent) and as 0 after (none is).
            'free shipping sent as a zero line' => [[self::line('A')], 'consumer', '', false, true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(string $reference): array
    {
        return ['reference' => $reference, 'quantity' => 1, 'totalAmount' => 10000, 'totalVatAmount' => 2000];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paidSession(): array
    {
        return [
            'sessionId' => 's1',
            'status' => 'completed',
            'references' => ['quoteId' => '42'],
            'data' => ['order' => ['amountIncVat' => 10000, 'currency' => 'SEK']],
        ];
    }

    /**
     * @return Order&MockObject
     */
    private function order(string $total, string $currency)
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['canHold', 'hold', 'addCommentToStatusHistory'])
            ->getMock();
        $order->setData(['grand_total' => $total, 'order_currency_code' => $currency, 'increment_id' => '100']);

        return $order;
    }

    /**
     * @param array<int, array<string, mixed>> $orderedLines the Briqpay lines of the cart ordered
     */
    private function guard(array $orderedLines, string $customerType): OrderCreationGuard
    {
        $cart = $this->createMock(GenerateCart::class);
        $cart->method('getCartDataFor')->willReturn(['cart' => $orderedLines]);
        $setupConfig = $this->createMock(SetupConfig::class);
        $setupConfig->method('getSetupConfig')->willReturn(['customer_type' => $customerType]);

        return new OrderCreationGuard(
            $this->createMock(LockManagerInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(Logger::class),
            $cart,
            new CompareData(),
            $setupConfig
        );
    }
}
