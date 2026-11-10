<?php
declare(strict_types=1);

namespace Briqpay\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * The status of an order created in the admin while its payment link waits to be paid.
 * It belongs to the "pending payment" state, but is not the "pending_payment" status:
 * Magento's cron cancels orders with that status after a few hours, and a link is often
 * paid later than that.
 */
class AddAwaitingPaymentStatus implements DataPatchInterface
{
    public const STATUS = 'briqpay_awaiting_payment';

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('sales_order_status'),
            ['status' => self::STATUS, 'label' => 'Awaiting Briqpay payment'],
            ['label']
        );
        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('sales_order_status_state'),
            [
                'status' => self::STATUS,
                'state' => Order::STATE_PENDING_PAYMENT,
                'is_default' => 0,
                'visible_on_front' => 1,
            ],
            ['state']
        );

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases()
    {
        return [];
    }
}
