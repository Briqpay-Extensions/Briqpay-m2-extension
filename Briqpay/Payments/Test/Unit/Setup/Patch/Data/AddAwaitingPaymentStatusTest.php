<?php
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Setup\Patch\Data;

use Briqpay\Payments\Setup\Patch\Data\AddAwaitingPaymentStatus;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

/**
 * Orders waiting for their payment link get a status of the "pending payment" state that
 * is not Magento's "pending_payment", which its cron cancels after a few hours.
 *
 * @covers \Briqpay\Payments\Setup\Patch\Data\AddAwaitingPaymentStatus
 */
class AddAwaitingPaymentStatusTest extends TestCase
{
    public function testTheStatusIsAddedToThePendingPaymentState(): void
    {
        $rows = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            static function (string $table, array $data) use (&$rows): int {
                $rows[] = [$table, $data];

                return 1;
            }
        );
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        (new AddAwaitingPaymentStatus($setup))->apply();

        $this->assertSame([
            ['sales_order_status', ['status' => 'briqpay_awaiting_payment', 'label' => 'Awaiting Briqpay payment']],
            ['sales_order_status_state', [
                'status' => 'briqpay_awaiting_payment',
                'state' => 'pending_payment',
                'is_default' => 0,
                'visible_on_front' => 1,
            ]],
        ], $rows);
        $this->assertNotSame('pending_payment', AddAwaitingPaymentStatus::STATUS);
    }
}
