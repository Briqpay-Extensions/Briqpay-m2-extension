<?php
/**
 * Builds quotes, orders, order items and credit memos for the tests from a
 * Scenarios entry. Order-side objects are real Magento classes created without their
 * constructor, so code that checks method_exists() takes the same branches it takes in
 * production.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Fixture;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * Couples to every sales document type by design.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class SalesObjects
{
    /**
     * The quote GenerateCart reads through the checkout session. GenerateCart only uses
     * magic getters, so a DataObject is a faithful stand-in.
     *
     * @param array<string, mixed> $scenario
     */
    public static function quote(array $scenario): DataObject
    {
        $items = [];
        foreach ($scenario['items'] as $item) {
            $items[] = self::quoteItem($item);
        }

        // Float zero, as the address holds it after collectTotals().
        $shippingAddress = new DataObject($scenario['shipping'] ?? ['shipping_amount' => 0.0]);

        return new DataObject([
            'id' => 1,
            'is_virtual' => $scenario['is_virtual'] ?? 0,
            'all_visible_items' => $items,
            'grand_total' => $scenario['grand_total'],
            'shipping_address' => $shippingAddress,
        ]);
    }

    /**
     * A quote item. "children" holds a bundle's child items; "children_calculated" marks
     * a bundle priced from its children, as Magento's quote item reports it.
     *
     * @param array<string, mixed> $data
     */
    private static function quoteItem(array $data, ?DataObject $parent = null): DataObject
    {
        $children = $data['children'] ?? [];
        unset($data['children']);
        $item = new class ($data + [
            'parent_item' => $parent,
            'product' => new DataObject([
                'image' => 'no_selection',
                'small_image' => 'no_selection',
                'thumbnail' => 'no_selection',
            ]),
        ]) extends DataObject {
            public function isChildrenCalculated(): bool
            {
                return (bool) $this->getData('children_calculated');
            }
        };
        $childItems = [];
        foreach ($children as $child) {
            $childItems[] = self::quoteItem($child, $item);
        }
        $item->setData('children', $childItems);

        return $item;
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array<string, OrderItem> keyed by SKU
     */
    public static function orderItems(array $scenario): array
    {
        $items = [];
        foreach ($scenario['items'] as $item) {
            $orderItem = self::newWithoutConstructor(OrderItem::class);
            $data = $item;
            $data['qty_ordered'] = $item['qty'];
            unset($data['qty']);
            $orderItem->setData($data);
            $items[$item['sku']] = $orderItem;
        }

        return $items;
    }

    /**
     * The order items PaymentMethod::capture() passes to CaptureOrder: the visible order
     * items with the invoiced quantity set as "quantity".
     *
     * @param array<string, OrderItem> $orderItems
     * @param array<string, int|float> $qtyBySku
     * @return array<int, OrderItem>
     */
    public static function captureCart(array $orderItems, array $qtyBySku): array
    {
        $cart = [];
        foreach ($qtyBySku as $sku => $qty) {
            $item = clone $orderItems[$sku];
            $item->setQuantity($qty);
            $cart[] = $item;
        }

        return $cart;
    }

    /**
     * @param array<string, mixed> $scenario
     * @param array<int, DataObject> $invoices
     * @param array<int, string>|null $notes receives the order-history comments added
     */
    public static function order(TestCase $test, array $scenario, array $invoices = [], ?array &$notes = null): Order
    {
        $order = self::partialMock($test, Order::class, ['getInvoiceCollection', 'addCommentToStatusHistory']);
        $order->setData([
            'entity_id' => 500,
            'increment_id' => '000000500',
            'order_currency_code' => 'SEK',
            'briqpay_session_id' => Scenarios::SESSION_ID,
            'shipping_amount' => $scenario['shipping']['shipping_amount'] ?? '0.0000',
            'shipping_incl_tax' => $scenario['shipping']['shipping_incl_tax'] ?? '0.0000',
            'shipping_description' => $scenario['shipping']['shipping_description'] ?? null,
            'grand_total' => $scenario['grand_total'],
        ]);
        $order->method('getInvoiceCollection')->willReturn(self::invoiceCollection($invoices));
        $order->method('addCommentToStatusHistory')->willReturnCallback(
            static function ($comment) use (&$notes, $order) {
                $notes[] = (string) $comment;

                return $order;
            }
        );

        return $order;
    }

    /**
     * A previously paid invoice, as CaptureOrder inspects it.
     */
    public static function paidInvoice(
        string $shippingAmount,
        string $incrementId = '',
        string $captureId = ''
    ): DataObject {
        return new DataObject([
            'state' => Order\Invoice::STATE_PAID,
            'shipping_amount' => $shippingAmount,
            'increment_id' => $incrementId,
            'transaction_id' => $captureId,
        ]);
    }

    /**
     * @param array<string, OrderItem> $orderItems
     * @param array<string, int|float> $qtyBySku
     * @param array<string, mixed> $totals
     * @param array<string, array<string, mixed>> $itemTotals credit memo item totals by SKU
     */
    public static function creditmemo(
        TestCase $test,
        Order $order,
        array $orderItems,
        array $qtyBySku,
        array $totals,
        array $itemTotals = []
    ): Creditmemo {
        $items = [];
        foreach ($qtyBySku as $sku => $qty) {
            $orderItem = $orderItems[$sku];
            $item = self::newWithoutConstructor(CreditmemoItem::class);
            $item->setOrderItem($orderItem);
            $item->addData([
                'order_item_id' => $orderItem->getData('item_id'),
                'qty' => $qty,
                'sku' => $orderItem->getSku(),
                'name' => $orderItem->getName(),
            ] + ($itemTotals[$sku] ?? []));
            $items[] = $item;
        }

        $creditmemo = self::partialMock($test, Creditmemo::class, ['getOrder', 'getAllItems']);
        $creditmemo->setData($totals + [
            'entity_id' => 900,
            'increment_id' => '000000900',
            'order_id' => $order->getData('entity_id'),
            'shipping_amount' => '0.0000',
            'shipping_incl_tax' => '0.0000',
            'adjustment_positive' => '0.0000',
            'adjustment_negative' => '0.0000',
        ]);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn($items);

        return $creditmemo;
    }

    /**
     * @param array<int, DataObject> $invoices
     */
    private static function invoiceCollection(array $invoices): \IteratorAggregate
    {
        return new class ($invoices) implements \IteratorAggregate {
            /**
             * @var array<int, DataObject>
             */
            private array $invoices;

            /**
             * @param array<int, DataObject> $invoices
             */
            public function __construct(array $invoices)
            {
                $this->invoices = $invoices;
            }

            public function getSize(): int
            {
                return count($this->invoices);
            }

            /**
             * @return \ArrayIterator<int, DataObject>
             */
            public function getIterator(): \ArrayIterator
            {
                return new \ArrayIterator($this->invoices);
            }
        };
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<int, string> $methods
     * @return T&\PHPUnit\Framework\MockObject\MockObject
     */
    private static function partialMock(TestCase $test, string $class, array $methods)
    {
        $builder = new \PHPUnit\Framework\MockObject\MockBuilder($test, $class);

        return $builder->disableOriginalConstructor()->onlyMethods($methods)->getMock();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function newWithoutConstructor(string $class)
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
