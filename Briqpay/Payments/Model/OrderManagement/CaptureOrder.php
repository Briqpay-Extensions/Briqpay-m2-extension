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
        $this->logger->info('CaptureOrder::capture method called.');

        
        $currency = $order->getOrderCurrencyCode();
        $this->orderCurrency = $order->getOrderCurrencyCode();
        // Calculate total amounts
        $amountIncVat =$captureAmount*100;

        $store = $this->storeManager->getStore();
        $baseCurrencyCode = $store->getBaseCurrencyCode();
        $this->baseCurrency = $baseCurrencyCode;
        $currentCurrencyCode = $store->getCurrentCurrencyCode();

// Get the website base currency code
        $website = $store->getWebsite();
        $websiteBaseCurrencyCode = $website->getBaseCurrencyCode();


        if ($websiteBaseCurrencyCode !== $currency) {
            $baseCurrency = $this->currencyFactory->create()->load($websiteBaseCurrencyCode);
     
            $amountIncVat = round($baseCurrency->convert($amountIncVat, $currency), 0);
        }


        $amountExVat = $this->getExvatTotalBasedOnCart($captureCart);
        $calculatedIncVat = (int)$this->getIncvatTotalBasedOnCart($captureCart);

        $this->logger->debug('Order details retrieved.', [
            'currency' => $currency,
            'amountIncVat' => $amountIncVat,
            'amountIncVat_Calculated' => $calculatedIncVat,
           'amountExVat' => $amountExVat,
            'orderId' => $order->getIncrementId()
        ]);

    

        // Get the Briqpay session ID from the quote
        $briqpaySessionId = $order->getData('briqpay_session_id');

        if (!$briqpaySessionId) {
            $this->logger->error('Briqpay session ID is not available in the quote.');
            throw new LocalizedException(__('Briqpay session ID is not available in the quote.'));
        }

        $this->logger->info('Briqpay session ID retrieved.', [
            'briqpaySessionId' => $briqpaySessionId
        ]);

        // Prepare cart items for the current capture
        $cartItems = $this->prepareCartItems($captureCart);

        $weetotals = 0;
        if ($this->weeeHelper->isEnabled()) {
            foreach ($cartItems as $cartitemcheck) {
                if ($cartitemcheck['productType'] === "surcharge") {
                    $amountExVat += $cartitemcheck['unitPrice']*$cartitemcheck['quantity'];
                    $weeTax = $cartitemcheck['taxRate'];
                    $weeTaxMultiplier = 1;
                    if ($weeTax > 0) {
                        $weeTaxMultiplier = 1+ $weeTax/10000;
                    }
                    $weetotals = $cartitemcheck['unitPrice']*$cartitemcheck['quantity'] *$weeTaxMultiplier;
                   
                   // $this->logger->info("getRowWeeeTaxInclTax= ". $this->weeeHelper->getRowWeeeTaxInclTax($item));
                }
            }
        }
     
        if (abs(($calculatedIncVat+$weetotals) - $amountIncVat) > 1) {
            // Include shipping fee in the cart items
            $shippingItem = $this->prepareShippingItem($order);
            if ($shippingItem) {
                $cartItems[] = $shippingItem;
                // Adjust amountExVat to include shipping
                $amountExVat += $shippingItem['unitPrice'];
            }
        }
        
        
        // Prepare request body
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

        $this->logger->debug('Request body prepared for Briqpay capture.', [
            'body' => $body
        ]);

        // Make API request to Briqpay
        $uri = '/v3/session/' . $briqpaySessionId . '/order/capture';

        try {
            $response = $this->apiClient->request('POST', $uri, $body);
            $this->logger->info('Briqpay capture request sent.', [
            'response' => $response
            ]);

            // Check if remaining amount is zero or less to mark the order as completed
            $grandTotal = $order->getGrandTotal();
            $totalPaid = $order->getTotalPaid();

            if ($totalPaid >= $grandTotal) {
                // Update order status to "completed"
                $order->setState(\Magento\Sales\Model\Order::STATE_COMPLETE)
                    ->setStatus(\Magento\Sales\Model\Order::STATE_COMPLETE);
                $this->orderRepository->save($order);
                $this->logger->info('Order status updated to "completed".');
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

    private function calculateAmountExVat(Invoice $invoice)
    {
        // Calculate the total amount excluding VAT for the order (products + shipping)
        $totalExVat = $invoice->getSubtotal();
        return $this->toApiFloat($totalExVat);
    }

    private function toApiFloat($float)
    {
        return (int) round($float * 100, 0);
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
               
            
                // Add the item to the cartItems array
                $cartItems[] = [
                'productType' => 'physical',
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
    
    

    private function getExvatTotalBasedOnCart($captureCart)
    {
        $exvat = 0;


        
        
        
        foreach ($captureCart as $item) {
            $this->logger->info("checkin item...".$item->getId());
            if ($item->getProductType() !== 'shipping') {
                $price =  $item->getPrice();
                
                $exvat += $this->toApiFloat($price)  * $item->getQuantity();
            }
        }
    
        return $exvat;
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
            'orderId' => $order->getOrder()->getIncrementId()
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
