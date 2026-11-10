<?php
/**
 * The checks both order paths run before turning a quote into an order for a Briqpay payment.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\OrderCreationGuard;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\OrderCreationGuard
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 */
class OrderCreationGuardTest extends TestCase
{
    /**
     * @var LockManagerInterface&MockObject
     */
    private $lock;

    /**
     * @var array<int, Order>
     */
    private array $orders = [];

    protected function setUp(): void
    {
        $this->lock = $this->createMock(LockManagerInterface::class);
    }

    public function testCreationRunsHoldingTheSessionLockAndReleasesIt(): void
    {
        $this->lock->expects($this->once())->method('lock')->with('briqpay_order_s1', 30)->willReturn(true);
        $this->lock->expects($this->once())->method('unlock')->with('briqpay_order_s1');

        $this->assertSame('created', $this->guard()->withLock('s1', static function (): string {
            return 'created';
        }));
    }

    public function testLockIsReleasedWhenCreationFails(): void
    {
        $this->lock->method('lock')->willReturn(true);
        $this->lock->expects($this->once())->method('unlock');

        $this->expectException(\RuntimeException::class);

        $this->guard()->withLock('s1', static function (): void {
            throw new \RuntimeException('Out of stock');
        });
    }

    public function testNothingIsCreatedWhileAnotherRequestHoldsTheSession(): void
    {
        $this->lock->method('lock')->willReturn(false);
        $created = false;

        try {
            $this->guard()->withLock('s1', static function () use (&$created): void {
                $created = true;
            });
            $this->fail('No exception');
        } catch (LocalizedException $e) {
            $this->assertFalse($created);
        }
    }

    public function testExistingOrderIsFoundForTheQuote(): void
    {
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $this->orders = [$order];

        $this->assertSame($order, $this->guard()->existingOrder(42));
    }

    /**
     * @dataProvider notPaidProvider
     * @param array<string, mixed> $session
     */
    public function testSessionNotPaidForTheQuoteIsRefused(array $session): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard()->assertPaidFor($session, 42);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public function notPaidProvider(): array
    {
        $paid = self::paidSession();

        return [
            'another quote' => [['references' => ['quoteId' => '41']] + $paid],
            'no quote reference' => [['references' => []] + $paid],
            'in progress' => [['status' => 'in_progress'] + $paid],
            'pending is not a session status' => [['status' => 'pending'] + $paid],
            'transaction rejected' => [['data' => ['transactions' => [['status' => 'rejected']]]] + $paid],
            'transaction cancelled' => [['data' => ['transactions' => [['status' => 'cancelled']]]] + $paid],
            'order rejected' => [['moduleStatus' => ['payment' => ['orderStatus' => 'order_rejected']]] + $paid],
        ];
    }

    public function testPaidSessionForTheQuoteIsAccepted(): void
    {
        $this->guard()->assertPaidFor(self::paidSession(), 42);
        $this->guard()->assertPaidFor(
            ['data' => ['transactions' => [['status' => 'pending']]]] + self::paidSession(),
            '42'
        );

        $this->addToAssertionCount(2);
    }

    public function testOrderMatchingTheAuthorisationIsNotHeld(): void
    {
        $order = $this->order('100.0000', 'SEK');
        $order->expects($this->never())->method('hold');
        $order->expects($this->never())->method('addCommentToStatusHistory');

        $this->guard()->holdIfAmountDiffers($order, self::paidSession());
    }

    /**
     * The cart changed during payment: Briqpay authorised 100.00, the order is 2,100.00.
     *
     * @dataProvider differsProvider
     */
    public function testOrderDifferingFromTheAuthorisationIsHeldAndNoted(string $total, string $currency): void
    {
        $order = $this->order($total, $currency);
        $order->method('canHold')->willReturn(true);
        $order->expects($this->once())->method('hold');
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->stringContains('Briqpay authorised 100.00 SEK'));

        $this->guard()->holdIfAmountDiffers($order, self::paidSession());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function differsProvider(): array
    {
        return [
            'more' => ['2100.0000', 'SEK'],
            'a minor unit less' => ['99.9900', 'SEK'],
            'other currency' => ['100.0000', 'EUR'],
        ];
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
            'moduleStatus' => ['payment' => ['orderStatus' => 'order_approved_not_captured']],
            'data' => [
                'order' => ['amountIncVat' => 10000, 'currency' => 'SEK'],
                'transactions' => [['status' => 'approved']],
            ],
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

    private function guard(): OrderCreationGuard
    {
        $results = $this->createMock(OrderSearchResultInterface::class);
        $results->method('getItems')->willReturnCallback(function (): array {
            return $this->orders;
        });
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);
        $criteria = $this->createMock(SearchCriteriaBuilder::class);
        $criteria->method('addFilter')->willReturnSelf();
        $criteria->method('setPageSize')->willReturnSelf();
        $criteria->method('create')->willReturn(new SearchCriteria());

        return new OrderCreationGuard($this->lock, $repository, $criteria, $this->createMock(Logger::class));
    }
}
