<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Checkout\Model\Session as CheckoutSession;
use Briqpay\Payments\Logger\Logger;
use Magento\Weee\Helper\Data as WeeeHelper;
use Magento\Catalog\Helper\Image as ImageHelper;
use Briqpay\Payments\Model\Utility\Money\CartLine;
use Briqpay\Payments\Model\Utility\Money\CartBalancer;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Briqpay\Payments\Model\Utility\Money\VatSplit;

class GenerateCart
{
    private $checkoutSession;
    private $logger;
    protected $weeeHelper;
    private $imageHelper;
    private $cartLine;
    private $cartBalancer;

    /**
     * SKUs on more than one line of the cart being built, see reference().
     *
     * @var array<string, true>
     */
    private $repeatedSkus = [];

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
        return $this->getCartDataFor($this->checkoutSession->getQuote());
    }

    /**
     * The Briqpay cart of a given quote (the checkout's, or an admin order's).
     *
     * @param mixed $activeCart
     * @return array<string, mixed> ['cart' => array, 'amountIncVat' => int, 'amountExVat' => int]
     */
    public function getCartDataFor($activeCart): array
    {
        if (!$activeCart || !$activeCart->getId()) {
            return ['cart' => [], 'amountIncVat' => 0, 'amountExVat' => 0];
        }

        $lines = [];
        $this->repeatedSkus = $this->repeatedSkus($this->pricedItems($activeCart));

        foreach ($this->pricedItems($activeCart) as $item) {
            $lines[] = $this->prepareCartItem($item);

            $discountLine = $this->prepareDiscountItem($item);
            if ($discountLine !== null) {
                $lines[] = $discountLine;
            }
        }

        if ($this->weeeHelper->isEnabled()) {
            foreach ($this->pricedItems($activeCart) as $item) {
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

        return $this->cartBalancer->balance($lines, $targetIncVat, true, $this->magentoTotals($activeCart));
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

    /**
     * The reference of an item's line. A SKU on more than one line (custom options, or a
     * bundle child also bought on its own) can carry different prices, so each of those
     * lines gets a reference of its own, made from the quote item id the order item
     * keeps, which captures and refunds use to find it.
     *
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $item
     * @return string
     */
    private function reference($item): string
    {
        $sku = (string) $item->getSku();
        if (!isset($this->repeatedSkus[substr($sku, 0, 64)]) || !$item->getItemId()) {
            return substr($sku, 0, 64);
        }

        return self::itemReference($sku, $item->getItemId());
    }

    /**
     * The reference of the line for one quote item of a SKU that is on several lines.
     *
     * @param string $sku
     * @param int|string $quoteItemId
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- shared with the capture and refund side
    public static function itemReference(string $sku, $quoteItemId): string
    {
        $suffix = '#' . $quoteItemId;

        return substr($sku, 0, 64 - strlen($suffix)) . $suffix;
    }

    /**
     * @param array<int, \Magento\Quote\Model\Quote\Item\AbstractItem> $items
     * @return array<string, true>
     */
    private function repeatedSkus(array $items): array
    {
        $seen = [];
        $repeated = [];
        foreach ($items as $item) {
            $sku = substr((string) $item->getSku(), 0, 64);
            if (isset($seen[$sku])) {
                $repeated[$sku] = true;
            }
            $seen[$sku] = true;
        }

        return $repeated;
    }

    /**
     * The items Magento prices: each visible item, except that a bundle priced from
     * its children (dynamic price) is replaced by its children, which carry the tax
     * rates, totals and discounts; the bundle line itself has no tax rate of its own.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array<int, \Magento\Quote\Model\Quote\Item\AbstractItem>
     */
    private function pricedItems($quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $children = self::childrenCalculated($item) ? (array) $item->getChildren() : [];
            if ($children === []) {
                $items[] = $item;
                continue;
            }
            foreach ($children as $child) {
                $items[] = $child;
            }
        }

        return $items;
    }

    /**
     * Whether the item is priced from its children (a dynamic-price bundle).
     *
     * @param object $item
     * @return bool
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- private pure helper, never intercepted
    private static function childrenCalculated($item): bool
    {
        return method_exists($item, 'isChildrenCalculated') && $item->isChildrenCalculated();
    }

    /**
     * The quote's non-zero totals by code (subtotal, shipping, discount, tax, and any
     * another module adds), for the log when a cart does not add up.
     *
     * @param mixed $quote
     * @return array<string, float>
     */
    private function magentoTotals($quote): array
    {
        $totals = [];
        if (!method_exists($quote, 'getTotals')) {
            return $totals;
        }
        foreach ((array) $quote->getTotals() as $code => $total) {
            $value = is_object($total) && method_exists($total, 'getValue') ? (float) $total->getValue() : 0.0;
            if ($value != 0.0) {
                $totals[(string) $code] = $value;
            }
        }

        return $totals;
    }

    private function prepareWeeTaxItem($item)
    {
        $qty = $this->getItemQty($item);
        // Magento's amounts for the whole row: the applied amount is per unit and excl.
        // VAT, which the row of a quantity above one would understate.
        $weeeRowExVat = MinorUnits::fromFloat($this->weeeHelper->getWeeeTaxAppliedRowAmount($item));
        $weeeRowIncVat = $this->weeeHelper->isTaxable()
            ? MinorUnits::fromFloat($this->weeeHelper->getRowWeeeTaxInclTax($item))
            : $weeeRowExVat;
        $weeTaxRate = $this->weeeHelper->isTaxable()
            ? MinorUnits::fromFloat($item->getTaxPercent())
            : 0;

        return $this->cartLine->buildExact(
            $weeeRowIncVat,
            $qty,
            $weeTaxRate,
            $weeeRowIncVat - $weeeRowExVat,
            'surcharge',
            $this->reference($item) . '_weee_tax',
            'WEEE Tax for ' . $item->getName()
        );
    }

    private function prepareCartItem($item)
    {
        $qty = $this->getItemQty($item);
        if (abs($qty - round($qty)) > 0.000001) {
            // Briqpay takes whole quantities only, so a product sold by weight or
            // length cannot be paid with Briqpay; the method is not offered for such a
            // cart (PaymentMethod::isAvailable), and a session asked for anyway is refused.
            $this->logger->error('Briqpay takes whole quantities; the cart has a decimal quantity.', [
                'sku' => $item->getSku(),
                'quantity' => $qty,
            ]);
            throw new \Magento\Framework\Exception\LocalizedException(__(
                'Briqpay takes whole quantities only; "%1" has %2. No session was created.',
                $item->getSku(),
                $qty
            ));
        }
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

        // Magento's own row amounts, before the discount: the row incl. VAT and its VAT,
        // shared with the discount line so both fit the rate (VatSplit).
        $rowVat = $this->vatSplit($item)['grossVat'];
        $cartItem = $this->cartLine->buildExact(
            $rowTotalIncVat,
            $qty,
            $taxRate,
            $rowVat,
            $this->getProductType($item),
            $this->reference($item),
            $item->getName()
        );

        // Magento's placeholder (what the admin area resolves a product image to) is no
        // picture of the product; the line goes without one.
        if ($imageUrl && strpos($imageUrl, '/placeholder/') === false) {
            $cartItem['imageUrl'] = $imageUrl;
        }

        return $cartItem;
    }


    /**
     * The item's discount: the row incl. VAT less what Magento charges for it, and the
     * row's VAT less the VAT Magento charges, both from Magento's stored amounts. That
     * holds whether discounts apply to prices incl. or excl. VAT, before or after tax, so
     * the item line plus its discount line come to exactly Magento's row.
     */
    private function prepareDiscountItem($item)
    {
        $taxRate = (float) $item->getTaxPercent();
        $rowTotalIncVat = (float) $item->getRowTotalInclTax();
        $actualTaxAmount = (float) $item->getTaxAmount();
        $discountAmt = (float) $item->getDiscountAmount();

        $rowIncVat = MinorUnits::fromFloat($rowTotalIncVat);
        $rowExVat = $this->rowExVat($item);
        $chargedTax = MinorUnits::fromFloat($actualTaxAmount);
        $charged = $rowExVat - MinorUnits::fromFloat($discountAmt) + $chargedTax
            + MinorUnits::fromFloat((float) $item->getDiscountTaxCompensationAmount());
        $discountIncVatMinor = $rowIncVat - $charged;
        $discountVat = $this->vatSplit($item)['discountVat'];

        if ($discountIncVatMinor <= 0) {
            return null;
        }

        // A discount Magento applies after tax takes no VAT off, so its line carries none.
        $taxRateBp = $discountVat === 0 ? 0 : MinorUnits::fromFloat($taxRate);

        return $this->cartLine->buildExact(
            -$discountIncVatMinor,
            1,
            $taxRateBp,
            -$discountVat,
            'discount',
            $this->reference($item) . '_discount',
            'Discount for ' . $item->getName()
        );
    }


    private function prepareShippingItem($quote)
    {
        $shippingAddress = $quote->getShippingAddress();

        // A virtual quote is charged no shipping, though its shipping address may still
        // hold an amount from before.
        if ($quote->getIsVirtual() || !$shippingAddress || !$shippingAddress->getShippingAmount()) {
            return null;
        }

        $shippingExVat = (float) $shippingAddress->getShippingAmount();
        $shippingIncVat = (float) $shippingAddress->getShippingInclTax();
        $shippingTax = (float) $shippingAddress->getShippingTaxAmount();
        $shippingDiscount = (float) $shippingAddress->getShippingDiscountAmount();

        $taxPercent = (float) $shippingAddress->getShippingTaxPercent();
        if ($taxPercent == 0 && $shippingExVat > 0) {
            // The tax amount is on the discounted shipping, so with a shipping discount
            // only the undiscounted amounts give the rate.
            $taxPercent = $shippingDiscount > 0
                ? (($shippingIncVat - $shippingExVat) / $shippingExVat) * 100
                : ($shippingTax / $shippingExVat) * 100;
        }

        $taxRateBp = MinorUnits::fromFloat($taxPercent);
        $rowTotalIncVat = MinorUnits::fromFloat($shippingIncVat);
        // The VAT Magento charges on shipping, after any shipping discount.
        $shippingVat = $shippingAddress->getShippingTaxAmount() !== null ? MinorUnits::fromFloat($shippingTax) : null;
        if ($shippingDiscount > 0) {
            // What Magento charges for shipping, by the same rule as an item's discount.
            $rowTotalIncVat = MinorUnits::fromFloat($shippingExVat) - MinorUnits::fromFloat($shippingDiscount)
                + MinorUnits::fromFloat($shippingTax)
                + MinorUnits::fromFloat((float) $shippingAddress->getShippingDiscountTaxCompensationAmount());
            if ($rowTotalIncVat <= 0) {
                return null;
            }
        }

        if ($shippingVat !== null && !$shippingAddress->getShippingTaxPercent()) {
            $taxRateBp = $this->shippingRate($quote, $rowTotalIncVat, $shippingVat, $taxRateBp);
        }

        return $this->cartLine->buildExact(
            $rowTotalIncVat,
            1,
            $taxRateBp,
            $shippingVat,
            self::ITEM_TYPE_SHIPPING,
            'shipping',
            (string) ($shippingAddress->getShippingDescription() ?: 'Shipping')
        );
    }

    /**
     * The shipping tax rate. The quote stores none, and the rate worked out from rounded
     * amounts is not a real one (4.90 with VAT 0.79 gives 19.17 %), so the item rate
     * nearest to it that the shipping VAT fits; the worked-out rate when none does.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param int $totalIncVat shipping charged incl. VAT, minor units
     * @param int $vat shipping VAT, minor units
     * @param int $computedBp the rate worked out from the amounts, basis points
     * @return int basis points
     */
    private function shippingRate($quote, int $totalIncVat, int $vat, int $computedBp): int
    {
        $best = $computedBp;
        foreach ($this->pricedItems($quote) as $item) {
            $rateBp = MinorUnits::fromFloat((float) $item->getTaxPercent());
            if ($rateBp > 0 && VatSplit::fits($totalIncVat, $vat, $rateBp)
                && ($best === $computedBp || abs($rateBp - $computedBp) < abs($best - $computedBp))
            ) {
                $best = $rateBp;
            }
        }

        return $best;
    }

    /**
     * The row's VAT shared between the item line and its discount line (VatSplit): the row
     * VAT before the discount less the VAT Magento charges is what the discount takes off.
     *
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $item
     * @return array{grossVat: int, discountVat: int}
     */
    private function vatSplit($item): array
    {
        $rowIncVat = MinorUnits::fromFloat((float) $item->getRowTotalInclTax());
        $rowExVat = $this->rowExVat($item);
        $chargedTax = MinorUnits::fromFloat((float) $item->getTaxAmount());
        $charged = $rowExVat - MinorUnits::fromFloat((float) $item->getDiscountAmount()) + $chargedTax
            + MinorUnits::fromFloat((float) $item->getDiscountTaxCompensationAmount());

        return VatSplit::split(
            $rowIncVat,
            $rowIncVat - $rowExVat,
            $rowIncVat - $charged,
            ($rowIncVat - $rowExVat) - $chargedTax,
            MinorUnits::fromFloat((float) $item->getTaxPercent()),
            $this->getItemQty($item)
        );
    }

    /**
     * The row excl. VAT before discount, minor units. Magento stores it on every quote
     * item; without it, it is the row incl. VAT divided by one plus the tax rate, as
     * Magento derives it.
     *
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $item
     * @return int
     */
    private function rowExVat($item): int
    {
        if ($item->getRowTotal() !== null) {
            return MinorUnits::fromFloat((float) $item->getRowTotal());
        }

        return MinorUnits::fromFloat(
            (float) $item->getRowTotalInclTax() / (1 + (float) $item->getTaxPercent() / 100)
        );
    }

    private function getProductType($item)
    {
        return $item->getIsVirtual() ? self::ITEM_TYPE_VIRTUAL : self::ITEM_TYPE_PHYSICAL;
    }

    private function getItemQty($item)
    {
        if ($item->getParentItem() && self::childrenCalculated($item->getParentItem())) {
            // A dynamic bundle's child: its quantity per bundle times the bundles.
            return (float) $item->getQty() * (float) $item->getParentItem()->getQty();
        }
        $methods = ['getQty', 'getCurrentInvoiceRefundItemQty', 'getQtyOrdered'];
        foreach ($methods as $method) {
            if ($item->$method() !== null) {
                return (float) $item->$method();
            }
        }

        return 0;
    }
}
