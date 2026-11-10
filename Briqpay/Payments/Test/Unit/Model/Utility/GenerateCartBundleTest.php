<?php
/**
 * A bundle priced from its children (dynamic price) is sent as its children, each at
 * its own tax rate, and its captures and refunds follow whichever shape the session
 * holds: the children, or the single bundle line of a session created before 2.0.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Model\OrderManagement\CaptureOrder;
use Briqpay\Payments\Model\OrderManagement\SessionLedger;
use Briqpay\Payments\Test\Unit\Fixture\CheckoutCart;
use Briqpay\Payments\Test\Unit\Fixture\OrderManagementScenarios;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\GenerateCart
 * @covers \Briqpay\Payments\Model\OrderManagement\CaptureOrder::ledgerItems
 * @uses \Briqpay\Payments\Model\OrderManagement\SessionLedger
 * @uses \Briqpay\Payments\Model\Utility\Money\CartBalancer
 * @uses \Briqpay\Payments\Model\Utility\Money\CartLine
 * @uses \Briqpay\Payments\Model\Utility\Money\MinorUnits
 */
class GenerateCartBundleTest extends TestCase
{
    public function testDynamicBundleIsSentAsItsChildrenAtTheirOwnTaxRates(): void
    {
        $cart = CheckoutCart::cartData($this, OrderManagementScenarios::dynamicBundle());

        $lines = [];
        foreach ($cart['cart'] as $line) {
            $lines[$line['reference']] = [
                $line['quantity'],
                $line['taxRate'],
                $line['totalAmount'],
                $line['totalVatAmount'],
            ];
        }

        $this->assertSame([
            'KIT-A' => [2, 2500, 20000, 4000],
            'KIT-B' => [4, 600, 21200, 1200],
            'shipping' => [1, 2500, 4900, 980],
        ], $lines);
        $this->assertSame(46100, $cart['amountIncVat']);
    }

    public function testChildrenAreCapturedByTheirOwnLinesWhenTheSessionHoldsThem(): void
    {
        $ledger = new SessionLedger(['data' => ['order' => ['cart' => [
            ['productType' => 'physical', 'reference' => 'KIT-A', 'quantity' => 2],
            ['productType' => 'physical', 'reference' => 'KIT-B', 'quantity' => 4],
        ]]]]);

        $this->assertSame([
            ['reference' => 'KIT-A', 'quantity' => 1.0],
            ['reference' => 'KIT-B', 'quantity' => 2.0],
        ], CaptureOrder::ledgerItems($ledger, $this->childrenOfOneBundle()));
    }

    public function testChildrenAreCapturedThroughTheBundleLineOfAnEarlierSession(): void
    {
        $ledger = new SessionLedger(['data' => ['order' => ['cart' => [
            ['productType' => 'physical', 'reference' => 'BUNDLE-A-B', 'quantity' => 2],
        ]]]]);

        $this->assertSame(
            [['reference' => 'BUNDLE-A-B', 'quantity' => 1.0]],
            CaptureOrder::ledgerItems($ledger, $this->childrenOfOneBundle())
        );
    }

    /**
     * One bundle's children as an invoice hands them to the capture.
     *
     * @return array<int, DataObject>
     */
    private function childrenOfOneBundle(): array
    {
        return [
            new DataObject(['sku' => 'KIT-A', 'qty' => 1.0, 'parent_sku' => 'BUNDLE-A-B', 'parent_qty' => 1.0]),
            new DataObject(['sku' => 'KIT-B', 'qty' => 2.0, 'parent_sku' => 'BUNDLE-A-B', 'parent_qty' => 1.0]),
        ];
    }
}
