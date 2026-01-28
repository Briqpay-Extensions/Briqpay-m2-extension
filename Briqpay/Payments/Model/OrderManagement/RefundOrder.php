<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;
use Magento\Weee\Helper\Data as WeeeHelper;

class RefundOrder
{
    private $setupConfig;
    private $apiClient;
    private $logger;
    private $orderRepository;
    protected $customTableFactory;
    protected $weeeHelper;

    const DEFAULT_QUANTITY_UNIT = 'pc';

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger,
        OrderRepositoryInterface $orderRepository,
        CustomTableFactory $customTableFactory,
        WeeeHelper $weeeHelper
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->customTableFactory = $customTableFactory;
        $this->weeeHelper = $weeeHelper;
    }

    public function refund(Creditmemo $creditmemo)
    {
        $this->logger->info('Briqpay: Starting Refund for Creditmemo #' . $creditmemo->getIncrementId());

        $order = $creditmemo->getOrder();
        $briqpaySessionId = $order->getData('briqpay_session_id');

        if (!$briqpaySessionId) {
            throw new LocalizedException(__('Briqpay session ID is not available.'));
        }

        // 1. Group items by their original Capture ID
        $captureGroups = $this->groupItemsByCaptureIdAndQuantity($creditmemo);

        foreach ($captureGroups as $captureId => $items) {
            // 2. CRITICAL: Calculate header totals from the cart items
            $totalIncVat = 0;
            $totalExVat = 0;
            foreach ($items as $item) {
                $totalIncVat += $item['totalAmount'];
                $totalExVat  += ($item['unitPrice'] * $item['quantity']);
            }

            $body = [
                "captureId" => $captureId,
                'data' => [
                    'order' => [
                        'currency' => $order->getOrderCurrencyCode(),
                        'amountIncVat' => (int)$totalIncVat,
                        'amountExVat' => (int)$totalExVat,
                        'cart' => $items
                    ]
                ]
            ];

            $this->logger->info("Briqpay: Sending refund for Capture ID: $captureId", ['body' => $body]);

            try {
                $this->apiClient->request('POST', '/v3/session/' . $briqpaySessionId . '/order/refund', $body);
            } catch (\Exception $e) {
                $this->logger->error('Briqpay Refund API Error: ' . $e->getMessage());
                throw new LocalizedException(__('Error refunding capture %1: %2', $captureId, $e->getMessage()));
            }
        }

        return null;
    }

    private function groupItemsByCaptureIdAndQuantity(Creditmemo $creditmemo)
    {
        $captureGroups = [];
        
        foreach ($creditmemo->getAllItems() as $item) {
            $qtyToRefund = (float)$item->getQty();
            if ($qtyToRefund <= 0) continue;

            $orderItem = $item->getOrderItem();
            
            // Fetch capture records for this specific item
            $collection = $this->customTableFactory->create()->getCollection();
            $collection->addFieldToFilter('item_id', $item->getOrderItemId());

            foreach ($collection as $captureRecord) {
                if ($qtyToRefund <= 0) break;

                $captureId = $captureRecord->getCaptureId();
                $availableInCapture = (float)$captureRecord->getQuantity();
                
                if ($availableInCapture <= 0) continue;

                $refundQty = min($qtyToRefund, $availableInCapture);
                $qtyToRefund -= $refundQty;

                // Update internal tracking
                $captureRecord->setQuantity($availableInCapture - $refundQty);
                $captureRecord->save();

                if (!isset($captureGroups[$captureId])) {
                    $captureGroups[$captureId] = [];
                }

                // MATH CONSISTENCY: Same logic as CaptureOrder
                $taxPercent = (float)$orderItem->getTaxPercent();
                $uPriceEx   = $this->toApiFloat($item->getPrice());
                $uPriceInc  = $this->toApiFloat($item->getPrice() * (1 + $taxPercent / 100));

                $lineTotalInc = (int)round($uPriceInc * $refundQty);
                $lineTotalEx  = (int)round($uPriceEx * $refundQty);

                $captureGroups[$captureId][] = [
                    'productType' => 'physical',
                    'reference' => substr($item->getSku(), 0, 64),
                    'name' => $item->getName(),
                    'quantity' => (int)$refundQty,
                    'quantityUnit' => self::DEFAULT_QUANTITY_UNIT,
                    'unitPrice' => $uPriceEx,
                    'taxRate' => (int)round($taxPercent * 100),
                    'discountPercentage' => 0,
                    'unitPriceIncVat' => $uPriceInc,
                    'totalAmount' => $lineTotalInc,
                    'totalVatAmount' => $lineTotalInc - $lineTotalEx
                ];

                // Handle WEEE
                if ($this->weeeHelper->isEnabled()) {
                    $weeeAmt = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
                    if ($weeeAmt > 0) {
                        $uWeee = $this->toApiFloat($weeeAmt);
                        $captureGroups[$captureId][] = [
                            'productType' => "surcharge",
                            'reference' => substr($item->getSku(), 0, 64).'_weee',
                            'name' => 'WEEE Tax: '.$item->getName(),
                            'quantity' => (int)$refundQty,
                            'quantityUnit' => self::DEFAULT_QUANTITY_UNIT,
                            'unitPrice' => $uWeee,
                            'taxRate' => 0,
                            'discountPercentage' => 0,
                            'unitPriceIncVat' => $uWeee,
                            'totalAmount' => (int)round($uWeee * $refundQty),
                            'totalVatAmount' => 0
                        ];
                    }
                }
            }
        }

        // Handle Shipping
        $shippingRefundAmount = (float)$creditmemo->getShippingAmount();
        if ($shippingRefundAmount > 0) {
            $shippingItem = $this->prepareShippingItem($creditmemo);
            // We assume shipping was captured in the first capture record found for this order
            $orderCaptureId = $this->getCaptureIdByOrderId($creditmemo->getOrderId());
            
            if ($orderCaptureId) {
                $captureGroups[$orderCaptureId][] = $shippingItem;
            }
        }

        return $captureGroups;
    }

    private function prepareShippingItem(Creditmemo $creditmemo)
    {
        $shippingEx  = (float)$creditmemo->getShippingAmount();
        $shippingInc = (float)$creditmemo->getShippingInclTax();
        $taxRate = $shippingEx > 0 ? (($shippingInc - $shippingEx) / $shippingEx) * 100 : 0;

        return [
            'productType' => 'shipping_fee',
            'reference' => 'shipping',
            'name' => 'Shipping Refund',
            'quantity' => 1,
            'quantityUnit' => self::DEFAULT_QUANTITY_UNIT,
            'unitPrice' => $this->toApiFloat($shippingEx),
            'taxRate' => (int)round($taxRate * 100),
            'discountPercentage' => 0,
            'unitPriceIncVat' => $this->toApiFloat($shippingInc),
            'totalAmount' => $this->toApiFloat($shippingInc),
            'totalVatAmount' => $this->toApiFloat($shippingInc - $shippingEx)
        ];
    }

    private function getCaptureIdByOrderId($orderId)
    {
        $collection = $this->customTableFactory->create()->getCollection();
        $collection->addFieldToFilter('order_id', $orderId);
        $item = $collection->getFirstItem();
        return $item ? $item->getCaptureId() : null;
    }

    private function toApiFloat($val): int
    {
        return (int)round((float)$val * 100);
    }
}