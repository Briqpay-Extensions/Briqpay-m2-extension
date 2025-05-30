<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Briqpay\Payments\Logger\Logger;
use Magento\Weee\Helper\Data as WeeeHelper;

class GenerateCart
{
    private $checkoutSession;
    private $logger;
    private $priceCurrency;
    private $storeManager;
    protected $weeeHelper;

    const ITEM_TYPE_PHYSICAL = 'physical';
    const ITEM_TYPE_VIRTUAL = 'digital';
    const ITEM_TYPE_SHIPPING = 'shipping_fee';

    public function __construct(
        CheckoutSession $checkoutSession,
        Logger $logger,
        PriceCurrencyInterface $priceCurrency,
        StoreManagerInterface $storeManager,
        WeeeHelper $weeeHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->priceCurrency = $priceCurrency;
        $this->storeManager = $storeManager;
        $this->weeeHelper = $weeeHelper;
    }

    public function getCart()
    {
        $activeCart = $this->checkoutSession->getQuote();

        if (!$activeCart || !$activeCart->getId()) {
            return null;
        }

        $items = [];
        $carttest =$activeCart->getAllItems();
        
        foreach ($activeCart->getAllVisibleItems() as $item) {
            $items[] = $this->prepareCartItem($item);

            $discountLine = $this->prepareDiscountItem($item);
            if ($discountLine !== null) {
                $items[] = $discountLine;
            }
        }

        if ($this->weeeHelper->isEnabled()) {
            foreach ($activeCart->getAllVisibleItems() as $item) {
                $weetax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
                if ($weetax > 0) {
                    $items[] = $this->addWeeTaxItems($item);
                }
            }
        }
        // Add shipping as an item
        $shippingItem = $this->prepareShippingItem($activeCart);
        if ($shippingItem) {
            $items[] = $shippingItem;
        }

        return $items;
    }

    public function getTotalAmount()
    {
        $activeCart = $this->checkoutSession->getQuote();

        if (!$activeCart || !$activeCart->getId()) {
            return 0;
        }

        // Grand total includes tax
        $totalAmount = $activeCart->getGrandTotal();

        return $this->toApiFloat($totalAmount);
    }

    public function getTotalExAmount()
    {
        $activeCart = $this->checkoutSession->getQuote();
        if (!$activeCart || !$activeCart->getId()) {
            return 0;
        }

        $subtotalExclTax = $activeCart->getSubtotal(); // excl. tax
        $shippingAddress = $activeCart->getShippingAddress();
        $shippingExclTax = $shippingAddress ? $shippingAddress->getShippingAmount() : 0;

        // WEEE adjustments
        $weetotals = 0;
        if ($this->weeeHelper->isEnabled() && !$this->weeeHelper->includeInSubtotal()) {
            $weetotals = $this->weeeHelper->getTotalAmounts($activeCart->getAllVisibleItems());
        }

        // Calculate total discount excl. tax
        $discountExVatTotal = 0;
        foreach ($activeCart->getAllVisibleItems() as $item) {
            $discountIncVat = $item->getDiscountAmount();

            if ($discountIncVat > 0) {
                $taxRate = $item->getTaxPercent();
                $discountExVat = $discountIncVat / (1 + ($taxRate / 100));
                $discountExVatTotal += $discountExVat;
            }
        }

        $totalExAmount = $subtotalExclTax + $shippingExclTax + $weetotals - $discountExVatTotal;

        return $this->toApiFloat($totalExAmount);
    }

    private function addWeeTaxItems($item)
    {
        $store = $this->storeManager->getStore();
        $baseCurrencyCode = $store->getBaseCurrencyCode();
        $currentCurrencyCode = $store->getCurrentCurrencyCode();
        $website = $store->getWebsite();
        $websiteBaseCurrencyCode = $website->getBaseCurrencyCode();
    
    
        $price = $item->getPrice();
        if ($websiteBaseCurrencyCode !== $currentCurrencyCode) {
            $price = $this->priceCurrency->convert($item->getPrice(), $store);
        }

        $weetax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
        $getWeeeTaxAppliedRowAmount  = $this->weeeHelper->getWeeeTaxAppliedRowAmount($item);
        $weetaxInclTax = $this->weeeHelper->getWeeeTaxInclTax($item);
        $weeTaxRate = 0;
        if ($this->weeeHelper->isTaxable()) {
            $weeTaxRate =$this->toApiFloat($item->getTaxPercent());
        }
       
             return [
            'productType' => "surcharge",
            'reference' => substr($item->getSku(), 0, 64).'_weee_tax',
            'name' => 'WEEE Tax for '.$item->getName(),
            'quantity' => ceil($this->getItemQty($item)),
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($weetax),
            'taxRate' => $weeTaxRate ,
            'discountPercentage' => 0
             ];
    }
    private function prepareCartItem($item)
    {
        $store = $this->storeManager->getStore();
        $baseCurrencyCode = $store->getBaseCurrencyCode();
        $currentCurrencyCode = $store->getCurrentCurrencyCode();
        $website = $store->getWebsite();
        $websiteBaseCurrencyCode = $website->getBaseCurrencyCode();
        
        
        $price = $item->getPrice();
        if ($websiteBaseCurrencyCode !== $currentCurrencyCode) {
            $price = $this->priceCurrency->convert($item->getPrice(), $store);
        }
        return [
            'productType' => $this->getProductType($item),
            'reference' => substr($item->getSku(), 0, 64),
            'name' => $item->getName(),
            'quantity' => ceil($this->getItemQty($item)),
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($price),
            'taxRate' => $this->toApiFloat($item->getTaxPercent()),
            'discountPercentage' => 0
        ];
    }

    private function prepareDiscountItem($item)
    {
        $discountIncVat = $item->getDiscountAmount();
        //+ $item->getDiscountTaxCompensationAmount();

        if ($discountIncVat <= 0) {
            return null;
        }

        // Calculate tax rate from item
        $taxRate = $item->getTaxPercent(); // e.g. 25

        // Convert inc. VAT to ex. VAT
        $discountExVat = $discountIncVat / (1 + ($taxRate / 100));

        $store = $this->storeManager->getStore();
        $convertedDiscount = $this->priceCurrency->convert($discountExVat, $store);

        return [
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

    private function prepareShippingItem($quote)
    {
        $shippingAddress = $quote->getShippingAddress();
    
        if (!$shippingAddress || !$shippingAddress->getShippingAmount()) {
            return null;
        }
    
        $shippingTaxPercent = $shippingAddress->getShippingTaxPercent();
        if ($shippingTaxPercent == 0) {
            $shippingTaxPercent = $this->calculateShippingTaxPercent($shippingAddress);
        }
    
        return [
            'productType' => self::ITEM_TYPE_SHIPPING,
            'reference' => 'shipping',
            'name' => $shippingAddress->getShippingDescription(),
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($shippingAddress->getShippingAmount()),
            'taxRate' => $this->toApiFloat($shippingTaxPercent),
            'discountPercentage' => 0
        ];
    }
    
    private function calculateShippingTaxPercent($shippingAddress)
    {
        $shippingAmount = $shippingAddress->getShippingAmount();
        $shippingTaxAmount = $shippingAddress->getShippingTaxAmount();
    
        if ($shippingAmount > 0) {
            return ($shippingTaxAmount / $shippingAmount) * 100;
        }
    
        return 0;
    }

    private function getProductType($item)
    {
        
        return $item->getIsVirtual() ? self::ITEM_TYPE_VIRTUAL : self::ITEM_TYPE_PHYSICAL;
    }

    private function getItemQty($item)
    {
        $methods = ['getQty', 'getCurrentInvoiceRefundItemQty', 'getQtyOrdered'];
        foreach ($methods as $method) {
            if ($item->$method() !== null) {
                return $item->$method();
            }
        }

        return 0;
    }

    private function toApiFloat($float)
    {
        return (int) round($float * 100);
    }
}
