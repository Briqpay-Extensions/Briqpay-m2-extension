<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Briqpay\Payments\Logger\Logger;
use Magento\Weee\Helper\Data as WeeeHelper;
use Magento\Catalog\Helper\Image as ImageHelper;

class GenerateCart
{
    private $checkoutSession;
    private $logger;
    private $priceCurrency;
    private $storeManager;
    protected $weeeHelper;
    private $imageHelper;

    const ITEM_TYPE_PHYSICAL = 'physical';
    const ITEM_TYPE_VIRTUAL = 'digital';
    const ITEM_TYPE_SHIPPING = 'shipping_fee';

    public function __construct(
        CheckoutSession $checkoutSession,
        Logger $logger,
        PriceCurrencyInterface $priceCurrency,
        StoreManagerInterface $storeManager,
        WeeeHelper $weeeHelper,
        ImageHelper $imageHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->priceCurrency = $priceCurrency;
        $this->storeManager = $storeManager;
        $this->weeeHelper = $weeeHelper;
        $this->imageHelper = $imageHelper;
    }

    public function getCart()
    {
        $activeCart = $this->checkoutSession->getQuote();

        if (!$activeCart || !$activeCart->getId()) {
            return null;
        }

        $items = [];

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
        $store = $this->storeManager->getStore();
        $websiteBaseCurrencyCode = $store->getWebsite()->getBaseCurrencyCode();
        $currentCurrencyCode = $store->getCurrentCurrencyCode();

        foreach ($activeCart->getAllVisibleItems() as $item) {
            $taxRate = $item->getTaxPercent();
            $rowTotalIncVat = $item->getRowTotalInclTax();
            $actualTaxAmount = $item->getTaxAmount();

            if ($websiteBaseCurrencyCode !== $currentCurrencyCode) {
                $rowTotalIncVat = $this->priceCurrency->convert($rowTotalIncVat, $store);
                $actualTaxAmount = $this->priceCurrency->convert($actualTaxAmount, $store);
            }

            $undiscountedTax = $rowTotalIncVat - ($rowTotalIncVat / (1 + ($taxRate / 100)));
            $taxReduction = $undiscountedTax - $actualTaxAmount;

            $discountAmt = $item->getDiscountAmount();
            if ($websiteBaseCurrencyCode !== $currentCurrencyCode) {
                $discountAmt = $this->priceCurrency->convert($discountAmt, $store);
            }

            $discountIncVat = $discountAmt + $taxReduction;

            if ($discountIncVat > 0) {
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

        $weetax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
        $weeTaxRate = $this->weeeHelper->isTaxable()
            ? $this->toApiFloat($item->getTaxPercent())
            : 0;

        $weetax = $this->priceCurrency->convert($weetax, $store);

        return [
            'productType' => 'surcharge',
            'reference' => substr($item->getSku(), 0, 64) . '_weee_tax',
            'name' => 'WEEE Tax for ' . $item->getName(),
            'quantity' => ceil($this->getItemQty($item)),
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($weetax),
            'taxRate' => $weeTaxRate,
            'discountPercentage' => 0,
            'unitPriceIncVat' => $this->toApiFloat($weetax),
            'totalAmount' => $this->toApiFloat($weetax),
            'totalVatAmount' => 0,
        ];
    }


    private function prepareCartItem($item)
    {
        $store = $this->storeManager->getStore();
        $websiteBaseCurrencyCode = $store->getWebsite()->getBaseCurrencyCode();
        $currentCurrencyCode = $store->getCurrentCurrencyCode();

        $qty = ceil($this->getItemQty($item));

        // Magento-native values
        $unitPriceExVat = $item->getPrice();
        $unitPriceIncVat = $item->getPriceInclTax();
        $rowTotalIncVat = $item->getRowTotalInclTax();
        $taxAmount = $item->getTaxAmount();

        if ($websiteBaseCurrencyCode !== $currentCurrencyCode) {
            $unitPriceExVat = $this->priceCurrency->convert($unitPriceExVat, $store);
            $unitPriceIncVat = $this->priceCurrency->convert($unitPriceIncVat, $store);
            $rowTotalIncVat = $this->priceCurrency->convert($rowTotalIncVat, $store);
        }

        $taxRate = $item->getTaxPercent();
        $taxAmount = $rowTotalIncVat - ($rowTotalIncVat / (1 + ($taxRate / 100)));

        $product = $item->getProduct();
        $imageUrl = null;

        if ($product->getImage() && $product->getImage() !== 'no_selection') {
            $imageUrl = $this->imageHelper->init($product, 'product_base_image')->getUrl();
        } elseif ($product->getSmallImage() && $product->getSmallImage() !== 'no_selection') {
            $imageUrl = $this->imageHelper->init($product, 'product_small_image')->getUrl();
        } elseif ($product->getThumbnail() && $product->getThumbnail() !== 'no_selection') {
            $imageUrl = $this->imageHelper->init($product, 'product_thumbnail_image')->getUrl();
        }

        $cartItem = [
            'productType' => $this->getProductType($item),
            'reference' => substr($item->getSku(), 0, 64),
            'name' => $item->getName(),
            'quantity' => $qty,
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($unitPriceExVat),
            'taxRate' => $this->toApiFloat($item->getTaxPercent()),
            'discountPercentage' => 0,
            'unitPriceIncVat' => $this->toApiFloat($unitPriceIncVat),
            'totalAmount' => $this->toApiFloat($rowTotalIncVat),
            'totalVatAmount' => $this->toApiFloat($taxAmount),
        ];

        if ($imageUrl) {
            $cartItem['imageUrl'] = $imageUrl;
        }

        return $cartItem;
    }


    private function prepareDiscountItem($item)
    {
        $store = $this->storeManager->getStore();
        $websiteBaseCurrencyCode = $store->getWebsite()->getBaseCurrencyCode();
        $currentCurrencyCode = $store->getCurrentCurrencyCode();

        $taxRate = $item->getTaxPercent();
        $rowTotalIncVat = $item->getRowTotalInclTax();
        $actualTaxAmount = $item->getTaxAmount();
        $discountAmt = $item->getDiscountAmount();

        if ($websiteBaseCurrencyCode !== $currentCurrencyCode) {
            $rowTotalIncVat = $this->priceCurrency->convert($rowTotalIncVat, $store);
            $actualTaxAmount = $this->priceCurrency->convert($actualTaxAmount, $store);
            $discountAmt = $this->priceCurrency->convert($discountAmt, $store);
        }

        $undiscountedTax = $rowTotalIncVat - ($rowTotalIncVat / (1 + ($taxRate / 100)));
        $taxReduction = $undiscountedTax - $actualTaxAmount;

        $discountIncVat = $discountAmt + $taxReduction;

        if ($discountIncVat <= 0) {
            return null;
        }

        $discountExVat = $discountIncVat / (1 + ($taxRate / 100));
        $taxAmount = $discountIncVat - $discountExVat;

        return [
            'productType' => 'discount',
            'reference' => substr($item->getSku(), 0, 64) . '_discount',
            'name' => 'Discount for ' . $item->getName(),
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => -$this->toApiFloat($discountExVat),
            'taxRate' => $this->toApiFloat($taxRate),
            'discountPercentage' => 0,
            'unitPriceIncVat' => -$this->toApiFloat($discountIncVat),
            'totalAmount' => -$this->toApiFloat($discountIncVat),
            'totalVatAmount' => -$this->toApiFloat($taxAmount),
        ];
    }


    private function prepareShippingItem($quote)
    {
        $shippingAddress = $quote->getShippingAddress();

        if (!$shippingAddress || !$shippingAddress->getShippingAmount()) {
            return null;
        }

        $shippingExVat = $shippingAddress->getShippingAmount();
        $shippingIncVat = $shippingAddress->getShippingInclTax();
        $shippingTax = $shippingAddress->getShippingTaxAmount();

        $taxPercent = $shippingAddress->getShippingTaxPercent();
        if ($taxPercent == 0 && $shippingExVat > 0) {
            $taxPercent = ($shippingTax / $shippingExVat) * 100;
        }

        return [
            'productType' => self::ITEM_TYPE_SHIPPING,
            'reference' => 'shipping',
            'name' => $shippingAddress->getShippingDescription(),
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($shippingExVat),
            'taxRate' => $this->toApiFloat($taxPercent),
            'discountPercentage' => 0,
            'unitPriceIncVat' => $this->toApiFloat($shippingIncVat),
            'totalAmount' => $this->toApiFloat($shippingIncVat),
            'totalVatAmount' => $this->toApiFloat($shippingTax),
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
