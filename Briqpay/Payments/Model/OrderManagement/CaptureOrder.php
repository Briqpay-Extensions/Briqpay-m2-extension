<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\Exception\LocalizedException;
use Magento\Weee\Helper\Data as WeeeHelper;
use Briqpay\Payments\Model\Utility\Money\CartLine;
use Briqpay\Payments\Model\Utility\Money\CartBalancer;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Briqpay\Payments\Model\PaymentModule\ReadSession;

class CaptureOrder
{
    private $apiClient;
    private $logger;
    private $weeeHelper;
    private $cartLine;
    private $cartBalancer;
    private $readSession;

    const ITEM_TYPE_SHIPPING = 'shipping_fee';
    const DEFAULT_QUANTITY_UNIT = 'pc';

    public function __construct(
        ApiClient $apiClient,
        Logger $logger,
        WeeeHelper $weeeHelper,
        CartLine $cartLine,
        CartBalancer $cartBalancer,
        ReadSession $readSession
    ) {
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->weeeHelper = $weeeHelper;
        $this->cartLine = $cartLine;
        $this->cartBalancer = $cartBalancer;
        $this->readSession = $readSession;
    }

    public function capture($order, $captureCart, $captureAmount)
    {
        $this->logger->info('Briqpay: Starting capture for Order #' . $order->getIncrementId());

        $briqpaySessionId = $order->getData('briqpay_session_id');
        if (!$briqpaySessionId) {
            throw new LocalizedException(__('Briqpay session ID is missing from order.'));
        }

        // 1. Prepare items (+ WEEE surcharges) with strict math calculation
        $cartItems = $this->prepareCartItems($captureCart);

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
            $shippingItem = $this->prepareShippingItem($order, $briqpaySessionId);
            if ($shippingItem) $cartItems[] = $shippingItem;
        }

        // 4. Reconcile against the invoice amount Magento is actually charging - never
        //    re-summed from the lines, so a capture can never drift from or exceed the
        //    authorised amount, and any per-line rounding is settled on one line here.
        // allowFold=false: a capture must never fold its own rounding remainder into
        // shipping/discount - Briqpay validates those references' unitPrice against
        // what checkout already committed, and a capture-side fold almost never
        // reproduces that same value. See CartBalancer's docblock.
        $targetIncVat = MinorUnits::fromFloat($captureAmount);
        $balanced = $this->cartBalancer->balance($cartItems, $targetIncVat, false);

        $body = [
            'data' => [
                'order' => [
                    'currency' => $order->getOrderCurrencyCode(),
                    'amountIncVat' => $balanced['amountIncVat'],
                    'amountExVat' => $balanced['amountExVat'],
                    'cart' => $balanced['cart']
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

    private function prepareCartItems($captureCart): array
    {
        $cartItems = [];
        foreach ($captureCart as $item) {
            // FALLBACK: Try getQty() first (Invoice), then getQuantity() (Quote/Other)
            $qty = (float)($item->getQty() ?: $item->getQuantity());
            if ($qty <= 0) continue;

            // Determine if shipping placeholder
            $type = method_exists($item, 'getProductType') ? $item->getProductType() : '';
            if ($type === 'shipping') continue;

            // The order item's own row total/qty are constant for the whole order, so
            // they are the correct base to prorate this partial capture against.
            $orderedQty = (float)$item->getQtyOrdered();
            if ($orderedQty <= 0) continue;

            $taxRateBp = MinorUnits::fromFloat($item->getTaxPercent());
            $orderRowIncVatMinor = MinorUnits::fromFloat($item->getRowTotalInclTax());
            $capturedRowIncVatMinor = MinorUnits::divRound($orderRowIncVatMinor * $qty, $orderedQty);

            $cartItems[] = $this->cartLine->build(
                $capturedRowIncVatMinor,
                $qty,
                $taxRateBp,
                in_array($type, ['virtual', 'downloadable']) ? 'digital' : 'physical',
                substr($item->getSku(), 0, 64),
                $item->getName()
            );

            if ($this->weeeHelper->isEnabled()) {
                $orderWeeeIncVat = (float)$this->weeeHelper->getWeeeTaxAppliedAmount($item);
                if ($orderWeeeIncVat > 0) {
                    $weeTaxRateBp = $this->weeeHelper->isTaxable() ? $taxRateBp : 0;
                    $capturedWeeeMinor = MinorUnits::divRound(
                        MinorUnits::fromFloat($orderWeeeIncVat) * $qty,
                        $orderedQty
                    );

                    $cartItems[] = $this->cartLine->build(
                        $capturedWeeeMinor,
                        $qty,
                        $weeTaxRateBp,
                        'surcharge',
                        substr($item->getSku(), 0, 64) . '_weee',
                        'WEEE Tax: ' . $item->getName()
                    );
                }
            }
        }
        return $cartItems;
    }

    /**
     * Shipping is captured exactly once across the whole order lifecycle, and Briqpay
     * validates that a later capture's "shipping" line matches the unitPrice it already
     * has on file from checkout - which can differ from Magento's own nominal shipping
     * field if checkout folded a rounding remainder into it (see CartBalancer). So this
     * echoes back the exact line Briqpay itself returns for the session, rather than
     * recomputing shipping fresh from Magento's order fields, which is what causes a
     * real "has mismatching unitPrice" rejection from Briqpay's capture endpoint.
     * Falls back to recomputing only if the session can't be read.
     */
    private function prepareShippingItem($order, string $briqpaySessionId): array
    {
        $sessionLine = $this->findSessionCartLine($briqpaySessionId, self::ITEM_TYPE_SHIPPING);
        if ($sessionLine !== null) {
            return $sessionLine;
        }

        $shippingEx = (float)$order->getShippingAmount();
        $shippingInc = (float)$order->getShippingInclTax();
        $taxRateBp = $shippingEx > 0
            ? MinorUnits::round((($shippingInc - $shippingEx) / $shippingEx) * 10000)
            : 0;

        return $this->cartLine->build(
            MinorUnits::fromFloat($shippingInc),
            1,
            $taxRateBp,
            self::ITEM_TYPE_SHIPPING,
            'shipping',
            $order->getShippingDescription() ?: 'Shipping'
        );
    }

    /**
     * Looks up a line by productType in the session Briqpay currently has on record.
     * Returns null (rather than throwing) on any failure, so a transient read issue
     * falls back to recomputing instead of blocking the capture outright.
     */
    private function findSessionCartLine(string $sessionId, string $productType): ?array
    {
        try {
            $session = $this->readSession->getSession($sessionId);
            $cart = $session['data']['order']['cart'] ?? [];
            foreach ($cart as $line) {
                if (($line['productType'] ?? null) === $productType) {
                    return $line;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'CaptureOrder: could not read back session to reuse its ' . $productType
                . ' line, falling back to a recomputed one: ' . $e->getMessage()
            );
        }
        return null;
    }

    private function prepareDiscountItems($captureCart): array
    {
        $discounts = [];
        foreach ($captureCart as $item) {
            $qty = (float)($item->getQty() ?: $item->getQuantity());
            $orderedQty = (float)$item->getQtyOrdered();
            if ($qty <= 0 || $orderedQty <= 0) continue;

            // discount_amount is excl. VAT; add the tax compensation Magento tracks
            // separately to get the incl.-VAT discount, then prorate it the same way
            // as the row total above - getDiscountAmount() is the discount for the
            // full ordered quantity, not a per-unit figure.
            $discountExVatOrdered = (float)$item->getDiscountAmount();
            $discountTaxCompOrdered = method_exists($item, 'getDiscountTaxCompensationAmount')
                ? (float)$item->getDiscountTaxCompensationAmount()
                : 0.0;
            $discountIncVatOrdered = $discountExVatOrdered + $discountTaxCompOrdered;
            if ($discountIncVatOrdered <= 0) continue;

            $discountIncVatMinor = MinorUnits::divRound(
                MinorUnits::fromFloat($discountIncVatOrdered) * $qty,
                $orderedQty
            );
            if ($discountIncVatMinor <= 0) continue;

            $taxRateBp = MinorUnits::fromFloat($item->getTaxPercent());

            $discounts[] = $this->cartLine->build(
                -$discountIncVatMinor,
                1,
                $taxRateBp,
                'discount',
                substr($item->getSku(), 0, 64) . '_discount',
                'Discount: ' . $item->getName()
            );
        }
        return $discounts;
    }
}
