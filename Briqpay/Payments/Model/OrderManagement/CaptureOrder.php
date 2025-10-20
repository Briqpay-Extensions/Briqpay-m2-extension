<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Weee\Helper\Data as WeeeHelper;

class CaptureOrder
{
    private $setupConfig;
    private $apiClient;
    private $logger;
    private $priceCurrency;
    private $quoteFactory;
    private $orderRepository;
    private $storeManager;
    private $currencyFactory;
    private $weeeHelper;
    private $imageHelper;

    private string $orderCurrency;
    private string $baseCurrency;

    const ITEM_TYPE_SHIPPING = 'shipping_fee';
    const DEFAULT_QUANTITY_UNIT = 'pc';
    const DEFAULT_DISCOUNT_PERCENTAGE = 0;

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger,
        QuoteFactory $quoteFactory,
        OrderRepositoryInterface $orderRepository,
        PriceCurrencyInterface $priceCurrency,
        StoreManagerInterface $storeManager,
        CurrencyFactory $currencyFactory,
        WeeeHelper $weeeHelper,
        ImageHelper $imageHelper
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->orderRepository = $orderRepository;
        $this->priceCurrency = $priceCurrency;
        $this->storeManager = $storeManager;
        $this->currencyFactory = $currencyFactory;
        $this->weeeHelper = $weeeHelper;
        $this->imageHelper = $imageHelper;
    }

    /**
     * Capture an order via Briqpay.
     */
    public function capture($order, $captureCart, $captureAmount)
    {
        $this->logger->info('CaptureOrder::capture called.', [
            'orderId' => $order->getIncrementId(),
            'captureAmount' => $captureAmount
        ]);

        $this->orderCurrency = $order->getOrderCurrencyCode();
        $this->baseCurrency = $this->storeManager->getStore()->getBaseCurrencyCode();

        // Convert capture amount to minor units
        $amountIncVat = (int) round($captureAmount * 100);

        $calculatedIncVat = $this->getIncvatTotalBasedOnCart($captureCart);

        $briqpaySessionId = $order->getData('briqpay_session_id');
        if (!$briqpaySessionId) {
            throw new LocalizedException(__('Briqpay session ID is missing from order.'));
        }

        // FIX: pass $order to prepareCartItems()
        $cartItems = $this->prepareCartItems($captureCart, $order);

        // Add discount items
        $discountItems = $this->prepareDiscountItem($captureCart);
        foreach ($discountItems as $item) {
            if ($item !== null) $cartItems[] = $item;
        }

        // Add shipping if not already captured
        $shippingInclTax = $order->getShippingInclTax();
        $shippingAlreadyCaptured = false;
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ($invoice->getState() == Invoice::STATE_PAID
                && (float)$invoice->getShippingAmount() > 0) {
                $shippingAlreadyCaptured = true;
                break;
            }
        }

        if (!$shippingAlreadyCaptured && $shippingInclTax > 0) {
            $shippingItem = $this->prepareShippingItem($order);
            if ($shippingItem) $cartItems[] = $shippingItem;
        }

        $amountExVat = $this->getExVatFromCart($cartItems);

        $body = [
            'data' => [
                'order' => [
                    'currency' => $this->orderCurrency,
                    'amountIncVat' => $amountIncVat,
                    'amountExVat' => $amountExVat,
                    'cart' => $cartItems
                ]
            ]
        ];

        $uri = '/v3/session/' . $briqpaySessionId . '/order/capture';

        try {
            $response = $this->apiClient->request('POST', $uri, $body);

            // Mark order as complete if fully paid
            if ($order->getTotalPaid() >= $order->getGrandTotal()) {
                $order->setState(\Magento\Sales\Model\Order::STATE_COMPLETE)
                      ->setStatus(\Magento\Sales\Model\Order::STATE_COMPLETE);
                $this->orderRepository->save($order);
            }

            return $response;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Error capturing order: %1', $e->getMessage()));
        }
    }

    /**
     * Prepare cart items for Briqpay API.
     */
    private function prepareCartItems($captureCart, $order): array
    {
        $cartItems = [];
        $storeId = $order->getStoreId();

        foreach ($captureCart as $item) {
            if ($item->getQuantity() <= 0 || $item->getProductType() === 'shipping') continue;

            $product = $item->getProduct();
            $productType = in_array($item->getProductType(), ['virtual', 'downloadable']) ? 'digital' : 'physical';

            $imageUrl = null;
            if ($product && $product->getId()) {
                // Force the product store context
                $product->setStoreId($storeId);

                $imageAttr = $product->getImage();
                if (!$imageAttr || $imageAttr === 'no_selection') {
                    $imageAttr = $product->getSmallImage();
                }
                if (!$imageAttr || $imageAttr === 'no_selection') {
                    $imageAttr = $product->getThumbnail();
                }

                if ($imageAttr && $imageAttr !== 'no_selection') {
                    // Get media base URL
                    $mediaUrl = $this->storeManager->getStore($storeId)
                                    ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

                    // Build full URL to the catalog product image
                    $imageUrl = $mediaUrl . 'catalog/product' . $imageAttr;
                }
            }

            $cartItem = [
                'productType' => $productType,
                'reference' => substr($item->getSku(), 0, 64),
                'name' => $item->getName(),
                'quantity' => (int)$item->getQuantity(),
                'quantityUnit' => 'pc',
                'unitPrice' => $this->toApiFloat($item->getPrice()),
                'taxRate' => (int) round($item->getTaxPercent() * 100),
                'discountPercentage' => 0
            ];

            if ($imageUrl) $cartItem['imageUrl'] = $imageUrl;

            $cartItems[] = $cartItem;

            // Add WEEE tax if enabled
            if ($this->weeeHelper->isEnabled()) {
                $weeeItem = $this->addWeeTaxItems($item);
                if ($weeeItem) $cartItems[] = $weeeItem;
            }
        }

        return $cartItems;
    }

    private function addWeeTaxItems($item)
    {
        $weeeTax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
        if ($weeeTax <= 0) return null;

        $taxRate = $this->weeeHelper->isTaxable() ? (int) round($item->getTaxPercent() * 100) : 0;

        return [
            'productType' => "surcharge",
            'reference' => substr($item->getSku(), 0, 64).'_weee_tax',
            'name' => 'WEEE Tax for '.$item->getName(),
            'quantity' => (int)$item->getQuantity(),
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($weeeTax),
            'taxRate' => $taxRate,
            'discountPercentage' => 0
        ];
    }

    private function prepareDiscountItem($captureCart)
    {
        $discountItems = [];
        foreach ($captureCart as $item) {
            if ($item->getQuantity() <= 0 || $item->getProductType() === 'shipping') continue;

            $discount = $item->getDiscountAmount();
            if ($discount <= 0) continue;

            $taxRate = $item->getTaxPercent();
            $discountExVat = $discount / (1 + $taxRate / 100);

            $discountItems[] = [
                'productType' => 'discount',
                'reference' => substr($item->getSku(), 0, 64).'_discount',
                'name' => 'Discount for '.$item->getName(),
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => -$this->toApiFloat($discountExVat),
                'taxRate' => (int) round($taxRate * 100),
                'discountPercentage' => 0
            ];
        }
        return $discountItems;
    }

    private function prepareShippingItem($order): ?array
    {
        $shippingAmount = $order->getShippingAmount();
        if ($shippingAmount <= 0) return null;

        $taxRate = $order->getShippingAmount() > 0
            ? ($order->getShippingTaxAmount() / $order->getShippingAmount()) * 100
            : 0;

        return [
            'productType' => self::ITEM_TYPE_SHIPPING,
            'reference' => 'shipping',
            'name' => $order->getShippingDescription() ?: 'Shipping & Handling',
            'quantity' => 1,
            'quantityUnit' => self::DEFAULT_QUANTITY_UNIT,
            'unitPrice' => $this->toApiFloat($shippingAmount),
            'taxRate' => $this->toApiFloat($taxRate),
            'discountPercentage' => self::DEFAULT_DISCOUNT_PERCENTAGE
        ];
    }

    private function toApiFloat($float)
    {
        return (int) round($float * 100, 0);
    }

    private function getIncvatTotalBasedOnCart($captureCart)
    {
        $total = 0;
        foreach ($captureCart as $item) {
            if ($item->getProductType() === 'shipping') continue;

            $priceIncTax = $item->getPrice() * (1 + $item->getTaxPercent() / 100);
            $total += $this->toApiFloat($priceIncTax * $item->getQuantity());
        }
        return $total;
    }

    private function getExVatFromCart(array $cartItems): int
    {
        $totalExVat = 0;
        foreach ($cartItems as $item) {
            $totalExVat += $item['unitPrice'] * $item['quantity'];
        }
        return $totalExVat;
    }
}
