<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Checkout\Model\Session as CheckoutSession;
use Briqpay\Payments\Logger\Logger;
use Magento\Weee\Helper\Data as WeeeHelper;
use Magento\Catalog\Helper\Image as ImageHelper;
use Briqpay\Payments\Model\Utility\Money\CartLine;
use Briqpay\Payments\Model\Utility\Money\CartBalancer;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;

class GenerateCart
{
    private $checkoutSession;
    private $logger;
    protected $weeeHelper;
    private $imageHelper;
    private $cartLine;
    private $cartBalancer;

    const ITEM_TYPE_PHYSICAL = 'physical';
    const ITEM_TYPE_VIRTUAL = 'digital';
    const ITEM_TYPE_SHIPPING = 'shipping_fee';

    public function __construct(
        CheckoutSession $checkoutSession,
        Logger $logger,
        WeeeHelper $weeeHelper,
        ImageHelper $imageHelper,
        CartLine $cartLine,
        CartBalancer $cartBalancer
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->weeeHelper = $weeeHelper;
        $this->imageHelper = $imageHelper;
        $this->cartLine = $cartLine;
        $this->cartBalancer = $cartBalancer;
    }

    /**
     * Canonical entry point: builds every cart line and reconciles them against the
     * quote's grand total in a single pass. getCart() / getTotalAmount() /
     * getTotalExAmount() are thin wrappers kept for callers that only need one piece -
     * prefer this where all three are needed so the quote is only walked once.
     *
     * @return array ['cart' => array, 'amountIncVat' => int, 'amountExVat' => int]
     */
    public function getCartData(): array
    {
        $activeCart = $this->checkoutSession->getQuote();

        if (!$activeCart || !$activeCart->getId()) {
            return ['cart' => [], 'amountIncVat' => 0, 'amountExVat' => 0];
        }

        $lines = [];

        foreach ($activeCart->getAllVisibleItems() as $item) {
            $lines[] = $this->prepareCartItem($item);

            $discountLine = $this->prepareDiscountItem($item);
            if ($discountLine !== null) {
                $lines[] = $discountLine;
            }
        }

        if ($this->weeeHelper->isEnabled()) {
            foreach ($activeCart->getAllVisibleItems() as $item) {
                $weeeTax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
                if ($weeeTax > 0) {
                    $lines[] = $this->prepareWeeTaxItem($item);
                }
            }
        }

        $shippingLine = $this->prepareShippingItem($activeCart);
        if ($shippingLine) {
            $lines[] = $shippingLine;
        }

        $targetIncVat = MinorUnits::fromFloat($activeCart->getGrandTotal());

        return $this->cartBalancer->balance($lines, $targetIncVat);
    }

    public function getCart()
    {
        return $this->getCartData()['cart'];
    }

    public function getTotalAmount()
    {
        return $this->getCartData()['amountIncVat'];
    }

    public function getTotalExAmount()
    {
        return $this->getCartData()['amountExVat'];
    }

    private function prepareWeeTaxItem($item)
    {
        $qty = $this->getItemQty($item);
        $weeeRowIncVat = MinorUnits::fromFloat($this->weeeHelper->getWeeeTaxAppliedAmount($item));
        $weeTaxRate = $this->weeeHelper->isTaxable()
            ? MinorUnits::fromFloat($item->getTaxPercent())
            : 0;

        return $this->cartLine->build(
            $weeeRowIncVat,
            $qty,
            $weeTaxRate,
            'surcharge',
            substr($item->getSku(), 0, 64) . '_weee_tax',
            'WEEE Tax for ' . $item->getName()
        );
    }

    private function prepareCartItem($item)
    {
        $qty = $this->getItemQty($item);
        $rowTotalIncVat = MinorUnits::fromFloat($item->getRowTotalInclTax());
        $taxRate = MinorUnits::fromFloat($item->getTaxPercent());

        $product = $item->getProduct();
        $imageUrl = null;

        if ($product->getImage() && $product->getImage() !== 'no_selection') {
            $imageUrl = $this->imageHelper->init($product, 'product_base_image')->getUrl();
        } elseif ($product->getSmallImage() && $product->getSmallImage() !== 'no_selection') {
            $imageUrl = $this->imageHelper->init($product, 'product_small_image')->getUrl();
        } elseif ($product->getThumbnail() && $product->getThumbnail() !== 'no_selection') {
            $imageUrl = $this->imageHelper->init($product, 'product_thumbnail_image')->getUrl();
        }

        $cartItem = $this->cartLine->build(
            $rowTotalIncVat,
            $qty,
            $taxRate,
            $this->getProductType($item),
            substr($item->getSku(), 0, 64),
            $item->getName()
        );

        if ($imageUrl) {
            $cartItem['imageUrl'] = $imageUrl;
        }

        return $cartItem;
    }


    private function prepareDiscountItem($item)
    {
        $taxRate = (float) $item->getTaxPercent();
        $rowTotalIncVat = (float) $item->getRowTotalInclTax();
        $actualTaxAmount = (float) $item->getTaxAmount();
        $discountAmt = (float) $item->getDiscountAmount();

        // Magento's discount_amount is excl. VAT; the VAT that would have applied to it
        // (had the item not been discounted) is the gap between the undiscounted tax and
        // the tax Magento actually charged. Adding that back gives the discount incl. VAT.
        $undiscountedTax = $rowTotalIncVat - ($rowTotalIncVat / (1 + ($taxRate / 100)));
        $taxReduction = $undiscountedTax - $actualTaxAmount;
        $discountIncVat = $discountAmt + $taxReduction;

        $discountIncVatMinor = MinorUnits::fromFloat($discountIncVat);
        if ($discountIncVatMinor <= 0) {
            return null;
        }

        $taxRateBp = MinorUnits::fromFloat($taxRate);

        return $this->cartLine->build(
            -$discountIncVatMinor,
            1,
            $taxRateBp,
            'discount',
            substr($item->getSku(), 0, 64) . '_discount',
            'Discount for ' . $item->getName()
        );
    }


    private function prepareShippingItem($quote)
    {
        $shippingAddress = $quote->getShippingAddress();

        if (!$shippingAddress || !$shippingAddress->getShippingAmount()) {
            return null;
        }

        $shippingExVat = (float) $shippingAddress->getShippingAmount();
        $shippingIncVat = (float) $shippingAddress->getShippingInclTax();
        $shippingTax = (float) $shippingAddress->getShippingTaxAmount();

        $taxPercent = (float) $shippingAddress->getShippingTaxPercent();
        if ($taxPercent == 0 && $shippingExVat > 0) {
            $taxPercent = ($shippingTax / $shippingExVat) * 100;
        }

        $taxRateBp = MinorUnits::fromFloat($taxPercent);
        $rowTotalIncVat = MinorUnits::fromFloat($shippingIncVat);

        return $this->cartLine->build(
            $rowTotalIncVat,
            1,
            $taxRateBp,
            self::ITEM_TYPE_SHIPPING,
            'shipping',
            $shippingAddress->getShippingDescription()
        );
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
                return (float) $item->$method();
            }
        }

        return 0;
    }
}
