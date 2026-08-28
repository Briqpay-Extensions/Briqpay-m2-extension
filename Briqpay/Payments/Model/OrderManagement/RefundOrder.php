<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;
use Magento\Weee\Helper\Data as WeeeHelper;
use Briqpay\Payments\Model\Utility\Money\CartLine;
use Briqpay\Payments\Model\Utility\Money\CartBalancer;
use Briqpay\Payments\Model\Utility\Money\MinorUnits;
use Briqpay\Payments\Model\PaymentModule\ReadSession;

class RefundOrder
{
    private $setupConfig;
    private $apiClient;
    private $logger;
    protected $customTableFactory;
    protected $weeeHelper;
    private $cartLine;
    private $cartBalancer;
    private $readSession;

    const DEFAULT_QUANTITY_UNIT = 'pc';

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger,
        CustomTableFactory $customTableFactory,
        WeeeHelper $weeeHelper,
        CartLine $cartLine,
        CartBalancer $cartBalancer,
        ReadSession $readSession
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->customTableFactory = $customTableFactory;
        $this->weeeHelper = $weeeHelper;
        $this->cartLine = $cartLine;
        $this->cartBalancer = $cartBalancer;
        $this->readSession = $readSession;
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
        $captureGroups = $this->groupItemsByCaptureIdAndQuantity($creditmemo, $briqpaySessionId);

        // 2. Distribute the credit memo's actual grand total across capture groups
        //    proportionally to what each group's lines sum to, so the amounts we send
        //    always add up to what Magento is actually refunding. The last group
        //    absorbs the remainder so the split itself is exact, to the öre.
        $targets = $this->distributeTarget(
            MinorUnits::fromFloat($creditmemo->getGrandTotal()),
            $captureGroups
        );

        foreach ($captureGroups as $captureId => $lines) {
            // allowFold=false: same reasoning as CaptureOrder - a refund must never fold
            // its own rounding remainder into shipping/discount, only ever a standalone
            // line. See CartBalancer's docblock.
            $balanced = $this->cartBalancer->balance($lines, $targets[$captureId], false);

            $body = [
                "captureId" => $captureId,
                'data' => [
                    'order' => [
                        'currency' => $order->getOrderCurrencyCode(),
                        'amountIncVat' => $balanced['amountIncVat'],
                        'amountExVat' => $balanced['amountExVat'],
                        'cart' => $balanced['cart']
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

    /**
     * Splits a document-level target (the credit memo grand total) across capture
     * groups in proportion to each group's own line sum, with the last group taking
     * whatever remains so the parts always sum to the whole exactly.
     */
    private function distributeTarget(int $totalIncVat, array $captureGroups): array
    {
        $groupSums = [];
        $overallSum = 0;
        foreach ($captureGroups as $captureId => $lines) {
            $sum = 0;
            foreach ($lines as $line) {
                $sum += $line['totalAmount'];
            }
            $groupSums[$captureId] = $sum;
            $overallSum += $sum;
        }

        $captureIds = array_keys($groupSums);
        $lastId = $captureIds ? $captureIds[count($captureIds) - 1] : null;
        $targets = [];
        $assigned = 0;

        foreach ($groupSums as $captureId => $sum) {
            if ($captureId === $lastId) {
                $targets[$captureId] = $totalIncVat - $assigned;
                break;
            }
            $target = $overallSum > 0 ? MinorUnits::divRound($totalIncVat * $sum, $overallSum) : 0;
            $targets[$captureId] = $target;
            $assigned += $target;
        }

        return $targets;
    }

    private function groupItemsByCaptureIdAndQuantity(Creditmemo $creditmemo, string $briqpaySessionId)
    {
        $captureGroups = [];
        $orderCaptureId = null;

        foreach ($creditmemo->getAllItems() as $item) {
            $qtyToRefund = (float)$item->getQty();
            if ($qtyToRefund <= 0) continue;

            $orderItem = $item->getOrderItem();

            // The order item's own row total/qty are constant for the whole order, so
            // they are the correct base to prorate this partial refund against.
            $orderedQty = (float)$orderItem->getQtyOrdered();
            if ($orderedQty <= 0) continue;

            $taxRateBp = MinorUnits::fromFloat($orderItem->getTaxPercent());
            $orderRowIncVatMinor = MinorUnits::fromFloat($orderItem->getRowTotalInclTax());

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

                $refundRowIncVatMinor = MinorUnits::divRound($orderRowIncVatMinor * $refundQty, $orderedQty);

                $captureGroups[$captureId][] = $this->cartLine->build(
                    $refundRowIncVatMinor,
                    $refundQty,
                    $taxRateBp,
                    'physical',
                    substr($item->getSku(), 0, 64),
                    $item->getName()
                );

                // Handle WEEE
                if ($this->weeeHelper->isEnabled()) {
                    $orderWeeeIncVat = (float)$this->weeeHelper->getWeeeTaxAppliedAmount($orderItem);
                    if ($orderWeeeIncVat > 0) {
                        $weeTaxRateBp = $this->weeeHelper->isTaxable() ? $taxRateBp : 0;
                        $refundWeeeMinor = MinorUnits::divRound(
                            MinorUnits::fromFloat($orderWeeeIncVat) * $refundQty,
                            $orderedQty
                        );

                        $captureGroups[$captureId][] = $this->cartLine->build(
                            $refundWeeeMinor,
                            $refundQty,
                            $weeTaxRateBp,
                            'surcharge',
                            substr($item->getSku(), 0, 64) . '_weee',
                            'WEEE Tax: ' . $item->getName()
                        );
                    }
                }
            }
        }

        // Handle Shipping
        $shippingRefundAmount = (float)$creditmemo->getShippingAmount();
        if ($shippingRefundAmount > 0) {
            $shippingItem = $this->prepareShippingItem($creditmemo, $briqpaySessionId);
            // We assume shipping was captured in the first capture record found for this order
            $orderCaptureId = $orderCaptureId ?? $this->getCaptureIdByOrderId($creditmemo->getOrderId());

            if ($orderCaptureId) {
                $captureGroups[$orderCaptureId][] = $shippingItem;
            }
        }

        // Handle adjustments (adjustment fee / adjustment refund) - these aren't tied
        // to a specific item, so they ride along with the same capture as shipping.
        $adjustmentNet = (float)$creditmemo->getAdjustmentPositive() - (float)$creditmemo->getAdjustmentNegative();
        if ($adjustmentNet != 0.0) {
            $orderCaptureId = $orderCaptureId ?? $this->getCaptureIdByOrderId($creditmemo->getOrderId());

            if ($orderCaptureId) {
                $captureGroups[$orderCaptureId][] = $this->cartLine->build(
                    MinorUnits::fromFloat($adjustmentNet),
                    1,
                    0,
                    'surcharge',
                    'adjustment',
                    'Adjustment'
                );
            } else {
                $this->logger->error(
                    'Briqpay Refund: credit memo has a non-zero adjustment but no capture '
                    . 'record exists to attach it to - the refund total will be short.',
                    ['creditmemoId' => $creditmemo->getId(), 'adjustmentNet' => $adjustmentNet]
                );
            }
        }

        return $captureGroups;
    }

    /**
     * Shipping is refunded exactly once across the whole order lifecycle, and Briqpay
     * validates that this line's unitPrice matches the one it already has on file from
     * checkout - which can differ from Magento's own nominal shipping field if checkout
     * folded a rounding remainder into it (see CartBalancer). So this echoes back the
     * exact line Briqpay itself returns for the session, rather than recomputing
     * shipping fresh from the credit memo's fields, which is what causes a real "has
     * mismatching unitPrice" rejection from Briqpay's refund endpoint. Falls back to
     * recomputing only if the session can't be read.
     */
    private function prepareShippingItem(Creditmemo $creditmemo, string $briqpaySessionId)
    {
        $sessionLine = $this->findSessionCartLine($briqpaySessionId, 'shipping_fee');
        if ($sessionLine !== null) {
            $sessionLine['name'] = 'Shipping Refund';
            return $sessionLine;
        }

        $shippingEx = (float)$creditmemo->getShippingAmount();
        $shippingInc = (float)$creditmemo->getShippingInclTax();
        $taxRateBp = $shippingEx > 0
            ? MinorUnits::round((($shippingInc - $shippingEx) / $shippingEx) * 10000)
            : 0;

        return $this->cartLine->build(
            MinorUnits::fromFloat($shippingInc),
            1,
            $taxRateBp,
            'shipping_fee',
            'shipping',
            'Shipping Refund'
        );
    }

    /**
     * Looks up a line by productType in the session Briqpay currently has on record.
     * Returns null (rather than throwing) on any failure, so a transient read issue
     * falls back to recomputing instead of blocking the refund outright.
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
                'RefundOrder: could not read back session to reuse its ' . $productType
                . ' line, falling back to a recomputed one: ' . $e->getMessage()
            );
        }
        return null;
    }

    private function getCaptureIdByOrderId($orderId)
    {
        $collection = $this->customTableFactory->create()->getCollection();
        $collection->addFieldToFilter('order_id', $orderId);
        $item = $collection->getFirstItem();
        return $item ? $item->getCaptureId() : null;
    }
}
