<?php

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Logger\Logger;
use Exception;

class RoundingHelper
{
    protected $cart;
    private $logger;

    public function __construct(
        Logger $logger,
        GenerateCart $cart
    ) {
        $this->logger = $logger;
        $this->cart = $cart;
    }

    public function roundCart($session)
    {
        try {
            $this->logger->debug('Round Cart - Starting rounding check for: ' . json_encode($session));
            $items = $session['data']['order']['cart'];

            // Initialize total summary variable
            $totalSummary = 0;

            // Loop through each item in the cart
            foreach ($items as $item) {
                $unitPrice = $item['unitPrice'];
                $taxRate = $item['taxRate'];
                $quantity = $item['quantity'];

                // Calculate line total price and VAT
                $lineTotalPrice = $unitPrice * $quantity;
                $lineTotalVat = $lineTotalPrice * ($taxRate / 10000);

                // Add to total summary with VAT included
                $totalSummary += (int) round($lineTotalPrice + $lineTotalVat);
            }

            // Fetch the total amount from another method (assuming getTotalAmount() exists and returns the expected total)
            $totalAmount = $this->cart->getTotalAmount();

            // Log the computed total summary and the provided total amount
            $this->logger->debug('Round Cart - TotalLine summary: ' . $totalSummary);
            $this->logger->debug('Round Cart - Total amount: ' . $totalAmount);

            // Calculate the difference
            $difference = $totalSummary - $totalAmount;

            if ($difference !== 0) {
                // Log the adjusted total summary if there's a difference
                $this->logger->debug('Round Cart - After adjustment: ' . ($totalSummary - $difference));

                // Add a rounding item to the items array
                $items[] = [
                    'productType' => 'surcharge',
                    'reference' => 'Rounding',
                    'name' => 'Rounding',
                    'quantity' => 1,
                    'quantityUnit' => 'pc',
                    'unitPrice' => -$difference,
                    'taxRate' => 0,
                    'discountPercentage' => 0
                ];

                // Update the session with the modified items array
                $session['data']['order']['cart'] = $items;
            } else {
                $this->logger->debug('Round Cart - No difference between total summary and total amount.');
            }
            return $session;
        } catch (Exception $e) {
            $this->logger->error('Round Cart - Error in roundCart function: ' . $e->getMessage());
            return $session;
        }
    }
}
