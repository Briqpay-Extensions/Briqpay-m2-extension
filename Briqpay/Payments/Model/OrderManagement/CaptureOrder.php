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
    protected $weeeHelper;

    protected $currencyFactory;

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
        WeeeHelper $weeeHelper
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
    }

    public function capture($order, $captureCart, $captureAmount)
    {
        $this->logger->info('CaptureOrder::capture method called.', [
        'orderId' => $order->getIncrementId(),
        'captureAmount' => $captureAmount
        ]);

        $currency = $order->getOrderCurrencyCode();
        $this->orderCurrency = $currency;

        // Convert amount to integer minor units (e.g., cents)
        $amountIncVat = (int) round($captureAmount * 100);

        $store = $this->storeManager->getStore();
        $baseCurrencyCode = $store->getBaseCurrencyCode();
        $this->baseCurrency = $baseCurrencyCode;

        $currentCurrencyCode = $store->getCurrentCurrencyCode();
        $website = $store->getWebsite();
        $websiteBaseCurrencyCode = $website->getBaseCurrencyCode();

        // If website base currency differs from order currency, convert capture amount to base currency minor units
        if ($websiteBaseCurrencyCode !== $currency) {
            $baseCurrency = $this->currencyFactory->create()->load($websiteBaseCurrencyCode);
            $amountIncVat = (int) round($baseCurrency->convert($amountIncVat, $currency), 0);
        }

        // Calculate totals based on captureCart items
        $calculatedIncVat = (int) $this->getIncvatTotalBasedOnCart($captureCart);

        $briqpaySessionId = $order->getData('briqpay_session_id');
        if (!$briqpaySessionId) {
            $this->logger->error('Briqpay session ID is missing from order.');
            throw new LocalizedException(__('Briqpay session ID is not available in the order.'));
        }
        $this->logger->info('Briqpay session ID retrieved.', ['briqpaySessionId' => $briqpaySessionId]);

        // Prepare cart items and include discounts if any
        $cartItems = $this->prepareCartItems($captureCart);
        $discountItems = $this->prepareDiscountItem($captureCart);
        foreach ($discountItems as $item) {
            if ($item !== null) {
                $cartItems[] = $item;
            }
        }

        // Handle WEEE tax surcharge if enabled
        $weeTotals = 0;
        if ($this->weeeHelper->isEnabled()) {
            foreach ($cartItems as $cartItem) {
                if ($cartItem['productType'] === "surcharge") {
                    $amountExVat += $cartItem['unitPrice'] * $cartItem['quantity'];
                    $weeTax = $cartItem['taxRate'];
                    $weeTaxMultiplier = ($weeTax > 0) ? (1 + $weeTax / 10000) : 1;
                    $weeTotals += $cartItem['unitPrice'] * $cartItem['quantity'] * $weeTaxMultiplier;
                }
            }
        }

        // Retrieve totals from the order for shipping logic
        $shippingInclTax = $order->getShippingInclTax();
        $totalPreviouslyCaptured = $order->getTotalPaid();
        $grandTotal = $order->getGrandTotal();

        // Check if shipping was already invoiced / captured
        $shippingAlreadyInvoiced = false;
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ($invoice->getState() == Invoice::STATE_PAID
            && (float) $invoice->getShippingAmount() > 0
            ) {
                $shippingAlreadyInvoiced = true;
                break;
            }
        }
        $shippingAlreadyCaptured = $shippingAlreadyInvoiced;

        $this->logger->debug('Shipping and payment info', [
            'ShippingInclTax' => $shippingInclTax,
            'TotalPreviouslyCaptured' => $totalPreviouslyCaptured,
            'GrandTotal' => $grandTotal,
            'ShippingAlreadyCaptured' => $shippingAlreadyCaptured ? 'true' : 'false'
        ]);

        // Add shipping item only if not already captured and shipping cost is greater than zero
        if (!$shippingAlreadyCaptured && $shippingInclTax > 0) {
            $shippingItem = $this->prepareShippingItem($order);
            if ($shippingItem) {
                $cartItems[] = $shippingItem;
            }
        }

        $amountExVat = $this->getExVatFromCart($cartItems);

        $this->logger->debug('Calculation discrepancy check', [
        'differenceGreaterThan1' => abs(($calculatedIncVat + $weeTotals) - $amountIncVat) > 1
        ]);

        // Prepare request body for Briqpay
        $body = [
        'data' => [
            'order' => [
                'currency' => $currency,
                'amountIncVat' => round($amountIncVat, 0),
                'amountExVat' => $amountExVat,
                'cart' => $cartItems
            ]
        ]
        ];

        $this->logger->debug('Request body prepared for Briqpay capture.', ['body' => $body]);

        $uri = '/v3/session/' . $briqpaySessionId . '/order/capture';

        try {
            $response = $this->apiClient->request('POST', $uri, $body);
            $this->logger->info('Briqpay capture request sent.', ['response' => $response]);

            // If total paid covers grand total, mark order as complete
            $totalPaid = $order->getTotalPaid();

            if ($totalPaid >= $grandTotal) {
                $order->setState(\Magento\Sales\Model\Order::STATE_COMPLETE)
                ->setStatus(\Magento\Sales\Model\Order::STATE_COMPLETE);
                $this->orderRepository->save($order);
                $this->logger->info('Order status updated to "complete".');
            }

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Error capturing order: ' . $e->getMessage(), [
            'exception' => $e,
            'orderId' => $order->getIncrementId(),
            'body' => $body
            ]);
            throw new LocalizedException(__('Error capturing order: ' . $e->getMessage()));
        }
    }

    private function calculateAmountIncVat(Invoice $invoice)
    {
        // Calculate the total amount including VAT for the order (products + shipping)
        $totalIncVat = $invoice->getGrandTotal();
        return $this->toApiFloat($totalIncVat);
    }

    private function toApiFloat($float)
    {
        return (int) round($float * 100, 0);
    }

    private function filterCartLinesForCapture(array $cart, InvoiceInterface $invoice): array
    {
        $skuQtyToInvoice = [];
        foreach ($invoice->getAllItems() as $item) {
            if ($item->getQty() > 0) {
                $skuQtyToInvoice[$item->getSku()] = $item->getQty();
            }
        }

        $filtered = [];

        foreach ($cart as $line) {
            $ref = $line['reference'] ?? '';

            if ($line['productType'] === 'physical' && isset($skuQtyToInvoice[$ref])) {
                $filtered[] = $line;
            } elseif ($line['productType'] === 'shipping_fee') {
                $filtered[] = $line;
            } elseif ($line['productType'] === 'discount'
            && $this->isDiscountForReferencedSku($ref, $skuQtyToInvoice)
            ) {
                $filtered[] = $line;
            }
        }

        return $filtered;
    }

    private function isDiscountForReferencedSku(string $discountRef, array $skuQtyToInvoice): bool
    {
        foreach ($skuQtyToInvoice as $sku => $_) {
            if (str_contains($discountRef, $sku)) {
                return true;
            }
        }
        return false;
    }

    private function prepareCartItems($captureCart): array
    {
        $cartItems = [];

        foreach ($captureCart as $item) {
            // Check if item quantity is greater than 0 and exclude shipping items
            if ($item->getQuantity() > 0 && $item->getProductType() !== 'shipping') {
                $store = $this->storeManager->getStore();
            
                $unitPriceInclTax = $item->getPriceInclTax();
                $unitPriceExclTax = $item->getPrice(); // This should return the price excluding VAT
            
                // Calculate the tax rate
                $taxRate = $item->getTaxPercent()* 100;
                $productType = 'physical'; // Default to physical
                if ($item->getProductType() === \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL ||
                $item->getProductType() === \Magento\Downloadable\Model\Product\Type::TYPE_DOWNLOADABLE) {
                    $productType = 'digital';
                }
            
                // Add the item to the cartItems array
                $cartItems[] = [
                'productType' => $productType,
                'reference' => substr($item->getSku(), 0, 64),
                'name' => $item->getName(),
                'quantity' => (int) $item->getQuantity(),
                'quantityUnit' => 'pc',
                'unitPrice' => $this->toApiFloat($unitPriceExclTax),
                'taxRate' => round($taxRate, 0), // Ensure taxRate is correct
                'discountPercentage' => 0 // Adjust as needed
                ];

                if ($this->weeeHelper->isEnabled()) {
                    $weetax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
                    if ($weetax > 0) {
                        $cartItems[] = $this->addWeeTaxItems($item);
                    }
                }
            }
        }

        return $cartItems;
    }

    private function prepareDiscountItem($captureCart)
    {
        $cartItems = [];
        foreach ($captureCart as $item) {
            if ($item->getQuantity() > 0 && $item->getProductType() !== 'shipping') {
                $discountIncVat = $item->getDiscountAmount();

                if ($discountIncVat <= 0) {
                    continue;
                }

                // Calculate tax rate from item
                $taxRate = $item->getTaxPercent(); // e.g. 25

                // Convert inc. VAT to ex. VAT
                $discountExVat = $discountIncVat / (1 + ($taxRate / 100));

                $store = $this->storeManager->getStore();
                $convertedDiscount = $this->priceCurrency->convert($discountExVat, $store);

                $cartItems[] = [
                'productType' => 'discount',
                'reference' => substr($item->getSku(), 0, 64) . '_discount',
                'name' => 'Discount for ' . $item->getName(),
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => -$this->toApiFloat($convertedDiscount), // Excl. VAT
                'taxRate' => $this->toApiFloat($taxRate), // 2500 for 25%
                'discountPercentage' => 0
                ];
            }
        }
        return $cartItems;
    }

    private function addWeeTaxItems($item)
    {
        

        $weetax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
        $weeTaxRate = 0;
        if ($this->weeeHelper->isTaxable()) {
            $weeTaxRate =$this->toApiFloat($item->getTaxPercent());
        }
        
        
         return [
        'productType' => "surcharge",
        'reference' => substr($item->getSku(), 0, 64).'_weee_tax',
        'name' => 'WEEE Tax for '.$item->getName(),
        'quantity' => (int) $item->getQuantity(),
        'quantityUnit' => 'pc',
        'unitPrice' => $this->toApiFloat($weetax),
        'taxRate' => $weeTaxRate,
        'discountPercentage' => 0
         ];
    }
    
    private function getIncvatTotalBasedOnCart($captureCart)
    {
        $incvat = 0;
        foreach ($captureCart as $item) {
            $this->logger->info("checking item...".$item->getId());
            if ($item->getProductType() !== 'shipping') {
                $store = $this->storeManager->getStore();
                $baseCurrencyCode = $store->getBaseCurrencyCode();
                $currentCurrencyCode = $store->getCurrentCurrencyCode();
                $price = $item->getPrice();
                $quantity = $item->getQuantity();
                $taxRate = $item->getTaxPercent();
                // Check if tax rate is in decimal form, if not, convert it
                if ($taxRate > 1) {
                    $taxRate = $taxRate / 100;
                }
                // Calculate the price including tax
                $priceIncTax = $price * (1 + $taxRate);
                // Calculate the total price for this item
                $totalPriceIncTax = $priceIncTax * $quantity;
                // Add to the incvat total
                $incvat += $this->toApiFloat($totalPriceIncTax);
            }
        }
    
        // Log the final incvat total
        return $incvat;
    }
    
    

    private function getExVatFromCart(array $cartItems): float
    {
        $exvat = 0;

        foreach ($cartItems as $item) {
            $unitPrice = ($item['unitPrice'] * $item['quantity'])/100;
            $exvat+=$unitPrice;
        }

        return $this->toApiFloat($exvat);
    }

 /**
 * Prepare shipping item for the invoice.
 *
 * @param InvoiceInterface $invoice
 * @return array|null
 */
    private function prepareShippingItem($order): ?array
    {
        $shippingAmount = $order->getShippingAmount();
        $shippingTaxAmount = $order->getShippingTaxAmount();
        $shippingDescription = $order->getShippingDescription();

        if ($shippingAmount <= 0) {
            $this->logger->warning('Shipping item not found in the order.', [
            'orderId' => $order->getId(),
            'orderId' => $order->getIncrementId()
            ]);
            return null;
        }

        // Calculate the shipping tax percent
        $shippingTaxPercent = $this->calculateShippingTaxPercent($shippingAmount, $shippingTaxAmount);

        $shippingItem = [
        'productType' => self::ITEM_TYPE_SHIPPING,
        'reference' => 'shipping',
        'name' => $shippingDescription ?: 'Shipping & Handling',
        'quantity' => 1,
        'quantityUnit' => self::DEFAULT_QUANTITY_UNIT,
        'unitPrice' => $this->toApiFloat($shippingAmount),
        'taxRate' => $this->toApiFloat($shippingTaxPercent),
        'discountPercentage' => self::DEFAULT_DISCOUNT_PERCENTAGE,
        ];

      

        return $shippingItem;
    }

    /**
     * Calculate the shipping tax percent.
     *
     * @param float $shippingAmount
     * @param float $shippingTaxAmount
     * @return float
     */
    private function calculateShippingTaxPercent(float $shippingAmount, float $shippingTaxAmount): float
    {
        if ($shippingAmount > 0) {
            return ($shippingTaxAmount / $shippingAmount) * 100;
        }
        return 0.0;
    }
    /**
 * Calculate the tax rate based on the price excluding and including tax.
 *
 * @param float $priceExclTax
 * @param float $priceInclTax
 * @return float
 */
    private function calculateTaxRate(float $priceExclTax, float $priceInclTax): float
    {
        if ($priceExclTax > 0) {
            // Calculate tax rate as a percentage
            $taxRate = (($priceInclTax - $priceExclTax) / $priceExclTax) ;
            return $taxRate;
        }

        return 0.0;
    }
}
