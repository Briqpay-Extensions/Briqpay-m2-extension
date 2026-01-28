<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Weee\Helper\Data as WeeeHelper;

class CaptureOrder
{
    private $apiClient;
    private $logger;
    private $storeManager;
    private $weeeHelper;

    const ITEM_TYPE_SHIPPING = 'shipping_fee';
    const DEFAULT_QUANTITY_UNIT = 'pc';

    public function __construct(
        ApiClient $apiClient,
        Logger $logger,
        StoreManagerInterface $storeManager,
        WeeeHelper $weeeHelper
    ) {
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->weeeHelper = $weeeHelper;
    }

    public function capture($order, $captureCart, $captureAmount)
    {
        $this->logger->info('Briqpay: Starting capture for Order #' . $order->getIncrementId());

        $briqpaySessionId = $order->getData('briqpay_session_id');
        if (!$briqpaySessionId) {
            throw new LocalizedException(__('Briqpay session ID is missing from order.'));
        }

        // 1. Prepare items with strict math calculation
        $cartItems = $this->prepareCartItems($captureCart, $order);

        // 2. Add Discounts
        $discountItems = $this->prepareDiscountItems($captureCart);
        foreach ($discountItems as $dItem) {
            $cartItems[] = $dItem;
        }

        // 3. Add Shipping (only if not already captured)
        $shippingAlreadyCaptured = false;
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ($invoice->getState() == Invoice::STATE_PAID && (float)$invoice->getShippingAmount() > 0) {
                $shippingAlreadyCaptured = true;
                break;
            }
        }

        if (!$shippingAlreadyCaptured && (float)$order->getShippingInclTax() > 0) {
            $shippingItem = $this->prepareShippingItem($order);
            if ($shippingItem) $cartItems[] = $shippingItem;
        }

        // 4. CALCULATE TOTALS FROM CART ARRAY (Guarantees Briqpay validation passes)
        $totalIncVat = 0;
        $totalExVat = 0;
        foreach ($cartItems as $ci) {
            $totalIncVat += $ci['totalAmount'];
            $totalExVat  += ($ci['unitPrice'] * $ci['quantity']);
        }

        $body = [
            'data' => [
                'order' => [
                    'currency' => $order->getOrderCurrencyCode(),
                    'amountIncVat' => (int)$totalIncVat,
                    'amountExVat' => (int)$totalExVat,
                    'cart' => $cartItems
                ]
            ]
        ];

        try {
            return $this->apiClient->request('POST', '/v3/session/' . $briqpaySessionId . '/order/capture', $body);
        } catch (\Exception $e) {
            $this->logger->error('Briqpay Capture Error: ' . $e->getMessage());
            throw new LocalizedException(__('Error capturing order: %1', $e->getMessage()));
        }
    }

    private function prepareCartItems($captureCart, $order): array
    {
        $cartItems = [];
        foreach ($captureCart as $item) {
            // FALLBACK: Try getQty() first (Invoice), then getQuantity() (Quote/Other)
            $qty = $item->getQty() ?: $item->getQuantity();
            $qty = (float)$qty;

            if ($qty <= 0) continue;

            // Determine if shipping placeholder
            $type = method_exists($item, 'getProductType') ? $item->getProductType() : '';
            if ($type === 'shipping') continue;

            $taxPercent = (float)$item->getTaxPercent();
            $uPriceEx = $this->toApiFloat($item->getPrice());
            $uPriceInc = $this->toApiFloat($item->getPrice() * (1 + $taxPercent / 100));

            // MANDATORY: Recalculate totals based on the partial Qty to satisfy Briqpay validation
            $lineTotalInc = (int)round($uPriceInc * $qty);
            $lineTotalEx  = (int)round($uPriceEx * $qty);

            $cartItems[] = [
                'productType' => in_array($type, ['virtual', 'downloadable']) ? 'digital' : 'physical',
                'reference' => substr($item->getSku(), 0, 64),
                'name' => $item->getName(),
                'quantity' => (int)$qty,
                'quantityUnit' => self::DEFAULT_QUANTITY_UNIT,
                'unitPrice' => $uPriceEx,
                'taxRate' => (int)round($taxPercent * 100),
                'discountPercentage' => 0,
                'unitPriceIncVat' => $uPriceInc,
                'totalAmount' => $lineTotalInc,
                'totalVatAmount' => $lineTotalInc - $lineTotalEx
            ];
        }
        return $cartItems;
    }

    private function prepareShippingItem($order): array
    {
        $shippingEx = (float)$order->getShippingAmount();
        $shippingInc = (float)$order->getShippingInclTax();
        $taxRate = $shippingEx > 0 ? (($shippingInc - $shippingEx) / $shippingEx) * 100 : 0;

        return [
            'productType' => self::ITEM_TYPE_SHIPPING,
            'reference' => 'shipping',
            'name' => $order->getShippingDescription() ?: 'Shipping',
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

    private function prepareDiscountItems($captureCart): array
    {
        $discounts = [];
        foreach ($captureCart as $item) {
            $qty = $item->getQty() ?: $item->getQuantity();
            $discountAmt = (float)$item->getDiscountAmount();
            
            if ($qty <= 0 || $discountAmt <= 0) continue;

            $taxPercent = (float)$item->getTaxPercent();
            $uDiscountInc = $this->toApiFloat($discountAmt / $qty);
            $uDiscountEx = (int)round($uDiscountInc / (1 + $taxPercent / 100));

            $discounts[] = [
                'productType' => 'discount',
                'reference' => substr($item->getSku(), 0, 64) . '_discount',
                'name' => 'Discount: ' . $item->getName(),
                'quantity' => (int)$qty,
                'quantityUnit' => self::DEFAULT_QUANTITY_UNIT,
                'unitPrice' => -$uDiscountEx,
                'taxRate' => (int)round($taxPercent * 100),
                'discountPercentage' => 0,
                'unitPriceIncVat' => -$uDiscountInc,
                'totalAmount' => -(int)round($uDiscountInc * $qty),
                'totalVatAmount' => -(int)round(($uDiscountInc - $uDiscountEx) * $qty)
            ];
        }
        return $discounts;
    }

    private function toApiFloat($val): int
    {
        return (int)round((float)$val * 100);
    }
}