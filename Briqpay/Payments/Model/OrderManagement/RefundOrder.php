<?php

namespace Briqpay\Payments\Model\OrderManagement;

use Briqpay\Payments\Model\Config\SetupConfig;
use Briqpay\Payments\Rest\ApiClient;
use Briqpay\Payments\Logger\Logger;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;
use Magento\Weee\Helper\Data as WeeeHelper;

class RefundOrder
{
    private $setupConfig;
    private $apiClient;
    private $logger;
    private $quoteFactory;
        private $orderRepository;
    protected $customTableFactory;
    protected $weeeHelper;

    public function __construct(
        SetupConfig $setupConfig,
        ApiClient $apiClient,
        Logger $logger,
        QuoteFactory $quoteFactory,
        OrderRepositoryInterface $orderRepository,
        CustomTableFactory $customTableFactory,
        WeeeHelper $weeeHelper
    ) {
        $this->setupConfig = $setupConfig;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        $this->quoteFactory = $quoteFactory;
                $this->orderRepository = $orderRepository;
        $this->customTableFactory = $customTableFactory;
        $this->weeeHelper = $weeeHelper;
    }

    public function refund(Creditmemo $creditmemo)
    {
        $this->logger->info('RefundOrder::refund method called.');

        $order = $creditmemo->getOrder();
        $currency = $order->getOrderCurrencyCode();
        // Calculate total amounts
       
       // Get adjustment values from the credit memo
        $adjustmentRefund = $creditmemo->getAdjustmentPositive();
        $adjustmentNegative = $creditmemo->getAdjustmentNegative();

    
        if ($adjustmentRefund > 0 || $adjustmentNegative > 0) {
            throw new LocalizedException(__('Adjustment refund or Adjustmentfee not supported'));
        }

         // Get the Briqpay session ID from the quote
         $briqpaySessionId = $order->getData('briqpay_session_id');

        if (!$briqpaySessionId) {
            $this->logger->error('Briqpay session ID is not available in the order.');
            throw new LocalizedException(__('Briqpay session ID is not available in the order.'));
        }

        $this->logger->debug('Briqpay session ID retrieved.', [
            'briqpaySessionId' => $briqpaySessionId
        ]);

        $captureGroups = $this->groupItemsByCaptureIdAndQuantity($creditmemo);

        foreach ($captureGroups as $captureId => $items) {
            $this->logger->debug('Processing capture group.', [
                'captureId' => $captureId,
                'items' => $items
            ]);

         // Calculate total amounts
            $amountExVat = array_reduce($items, function ($carry, $item) {
                // Sum of ex-VAT prices
                return $carry + $item['unitPrice'] * $item['quantity'];
            }, 0);

            $amountIncVat = array_reduce($items, function ($carry, $item) {
                // Calculate the price including VAT
                $priceInclTax = $item['unitPrice'] * (1 + $item['taxRate'] / 10000);
                
     
                // Sum of prices including VAT
                return $carry + $priceInclTax * $item['quantity'];
            }, 0);

// Prepare request body
            $body = [
                "captureId" => $captureId,
                'data' => [
                    'order' => [
                        'currency' => $currency,
                        'amountIncVat' => round($amountIncVat, 0), // Adjust precision as needed
                        'amountExVat' => round($amountExVat, 0),  // Adjust precision as needed
                        'cart' => $items
                    ]
                ]
            ];


            $this->logger->debug('Request body prepared for Briqpay refund.', [
                'body' => $body
            ]);

            // Make API request to Briqpay
            $uri = '/v3/session/' . $briqpaySessionId . '/order/refund';

            try {
                $response = $this->apiClient->request('POST', $uri, $body);
                $this->logger->debug('Briqpay refund request sent.', [
                    'response' => $response
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Error refunding order: ' . $e->getMessage(), [
                    'exception' => $e,
                    'orderId' => $order->getIncrementId(),
                    'body' => $body
                ]);
                throw new LocalizedException(__('Error refunding order: ' . $e->getMessage()));
            }
        }

        return null;
    }

    private function addWeeTaxItems($item, $qty)
    {
        $weetax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
        $weeTaxRate = 0;
        if ($this->weeeHelper->isTaxable()) {
            $weeTaxRate =(int)($this->calculateTaxRate($item) * 100);
        }
       
        
             return [
            'productType' => "surcharge",
            'reference' => substr($item->getSku(), 0, 64).'_weee_tax',
            'name' => 'WEEE Tax for '.$item->getName(),
           'quantity' => (int)$qty,
            'quantityUnit' => 'pc',
            'unitPrice' => $this->toApiFloat($weetax),
            'taxRate' =>  $weeTaxRate,
            'discountPercentage' => 0
             ];
    }

    private function groupItemsByCaptureIdAndQuantity(Creditmemo $creditmemo)
    {
        $captureGroups = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $captureId = $this->getCaptureIdByItemId($item->getOrderItemId());
            $remainingQty = $item->getQty();

            // Fetch capture details for the item and handle quantities correctly
            $collection = $this->customTableFactory->create()->getCollection();
            $collection->addFieldToFilter('item_id', $item->getOrderItemId());

            foreach ($collection as $captureRecord) {
                if ($remainingQty <= 0) {
                    break;
                }

                $recordCaptureId = $captureRecord->getCaptureId();
                $captureQty = $captureRecord->getQuantity();
                if ($captureQty <= 0) {
                    continue;
                }

                $refundQty = min($remainingQty, $captureQty);
                $remainingQty -= $refundQty;

                $captureRecord->setQuantity($captureQty - $refundQty);
                $captureRecord->save();

                if (!isset($captureGroups[$recordCaptureId])) {
                    $captureGroups[$recordCaptureId] = [];
                }
             
                $unitPriceExclTax = $item->getPrice(); // This should return the price excluding VAT
                $captureGroups[$recordCaptureId][] = [
                    'productType' => 'physical',
                    'reference' => $item->getSku(),
                    'name' => $item->getName(),
                    'quantity' => (int) $refundQty,
                    'quantityUnit' => 'pc',
                    'unitPrice' => $this->toApiFloat($unitPriceExclTax),
                    'taxRate' => (int)($this->calculateTaxRate($item) * 100),
                    'discountPercentage' => 0 // Adjust as needed
                ];

                if ($this->weeeHelper->isEnabled()) {
                   
                  
                        $weetax = $this->weeeHelper->getWeeeTaxAppliedAmount($item);
                    if ($weetax > 0) {
                        $captureGroups[$recordCaptureId][] = $this->addWeeTaxItems($item, $refundQty);
                    }
                }
            }
        }

        // Include shipping fee in the correct capture group
        $shippingItem = $this->prepareShippingItem($creditmemo);
        if ($shippingItem) {
            $captureId = $this->getCaptureIdByOrderId($creditmemo->getOrderId());
            if (!isset($captureGroups[$captureId])) {
                $captureGroups[$captureId] = [];
            }
            $captureGroups[$captureId][] = $shippingItem;
        }

        return $captureGroups;
    }

    private function getCaptureIdByItemId($itemId)
    {
        // Retrieve the capture ID from the custom table using the item ID
        $collection = $this->customTableFactory->create()->getCollection();
        $collection->addFieldToFilter('item_id', $itemId);
        $item = $collection->getFirstItem();
        return $item->getCaptureId();
    }

    private function getCaptureIdByOrderId($orderId)
    {
        // Retrieve the capture ID from the custom table using the order ID
        $collection = $this->customTableFactory->create()->getCollection();
        $collection->addFieldToFilter('order_id', $orderId);
        $item = $collection->getFirstItem();
        return $item->getCaptureId();
    }

    private function toApiFloat($float)
    {
        return (int) round($float * 100);
    }

    private function prepareShippingItem(Creditmemo $creditmemo)
    {
        if ($creditmemo->getShippingAmount() > 0) {
            $shippingItem = [
                'productType' => 'shipping_fee',
                'reference' => 'shipping',
                'name' => 'Shipping Fee',
                'quantity' => 1,
                'quantityUnit' => 'pc',
                'unitPrice' => $this->toApiFloat($creditmemo->getShippingAmount()),
                'taxRate' => (int)($creditmemo->getShippingTaxAmount() > 0 ? ($creditmemo->getShippingTaxAmount() / $creditmemo->getShippingAmount()) * 10000 : 0),
                'discountPercentage' => 0 // Adjust as needed
            ];

           

            return $shippingItem;
        }

        // Log a warning if no shipping item is found
        $this->logger->warning('Shipping item not found in the credit memo.', [
            'creditmemoId' => $creditmemo->getId(),
            'orderId' => $creditmemo->getOrder()->getIncrementId()
        ]);

        return null;
    }

    private function calculateTaxRate($item)
    {
    // Calculate the tax rate based on item prices
        $priceExclTax = round($item->getPrice(), 2);
        $priceInclTax = round($item->getPriceInclTax(), 2);

        if ($priceExclTax > 0) {
            $taxRate = (($priceInclTax - $priceExclTax) / $priceExclTax) * 100;
            $roundedTaxRate = round($taxRate, 1); // Round to 2 decimal places
            
            return $roundedTaxRate;
        }

        return 0;
    }
}
