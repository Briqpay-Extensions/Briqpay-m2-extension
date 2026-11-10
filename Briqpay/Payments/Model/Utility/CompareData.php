<?php
namespace Briqpay\Payments\Model\Utility;

/**
 * Class CompareData
 * @package Briqpay\Payments\Model\Utility
 */
class CompareData
{
    /**
     * Compares two arrays for changes.
     *
     * @param array $sessionData
     * @param array $newData
     * @return bool
     */
    public function compareData(array $sessionData, array $newData): bool
    {
        if (empty($sessionData) && empty($newData)) {
            return false;
        }

        // Not compared: country, company name, company id and region.
        unset($sessionData['country']);
        unset($sessionData['companyName']);
        unset($sessionData['cin']);
        unset($sessionData['region']);
        unset($newData['country']);
        unset($newData['companyName']);
        unset($newData['cin']);
        unset($newData['region']);
        

        // Ensure both arrays have the same keys
        $this->ensureSameKeys($sessionData, $newData);
        $this->ensureSameKeys($newData, $sessionData);

        // Normalize the arrays
        $this->normalizeArray($sessionData);
        $this->normalizeArray($newData);

        return serialize($sessionData) !== serialize($newData);
    }

    /**
     * Compares two cart arrays for changes.
     *
     * @param array $sessionCart
     * @param array $newCart
     * @return bool
     */
    public function compareCartData(array $sessionCart, array $newCart): bool
    {
        if (count($sessionCart) !== count($newCart)) {
            return true;
        }

        foreach ($newCart as $index => $newItem) {
            if (!isset($sessionCart[$index]) || $sessionCart[$index] != $newItem) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Briqpay's cart lines differ from the cart's in reference, quantity or
     * amounts.
     *
     * @param array<int, array<string, mixed>> $sessionCart
     * @param array<int, array<string, mixed>> $cart
     * @return bool
     */
    public function linesDiffer(array $sessionCart, array $cart): bool
    {
        $key = static function (array $lines): array {
            return array_map(static function ($line): string {
                $line = (array) $line;

                return implode('|', [
                    (string) ($line['reference'] ?? ''),
                    (string) (float) ($line['quantity'] ?? 0),
                    (string) (int) ($line['totalAmount'] ?? 0),
                    (string) (int) ($line['totalVatAmount'] ?? 0),
                ]);
            }, array_values(array_filter($lines, static function ($line): bool {
                // A line of nothing (free shipping, as Magento reports it before its
                // totals are collected) is no difference.
                $line = (array) $line;

                return (int) ($line['totalAmount'] ?? 0) !== 0 || (int) ($line['totalVatAmount'] ?? 0) !== 0;
            })));
        };

        return $key($sessionCart) !== $key($cart);
    }

    /**
     * Compares the session total with the quote grand total.
     *
     * @param int $sessionTotal
     * @param int $quoteGrandTotal
     * @return bool
     */
    public function doesTotalsMatch(int $sessionTotal, int $quoteGrandTotal): bool
    {
        if ((int)$sessionTotal == (int)$quoteGrandTotal) {
            return true;
        }
        return false;
    }

    /**
     * Normalizes an array by recursively sorting its keys and values.
     *
     * @param array &$array
     * @return void
     */
    private function normalizeArray(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->normalizeArray($value);
            }
        }
        ksort($array);
    }

    /**
     * Ensures that both arrays have the same keys.
     *
     * @param array &$array1
     * @param array &$array2
     * @return void
     */
    private function ensureSameKeys(array &$array1, array &$array2): void
    {
        foreach ($array1 as $key => $value) {
            if (!array_key_exists($key, $array2)) {
                $array2[$key] = null;
            }
        }
        foreach ($array2 as $key => $value) {
            if (!array_key_exists($key, $array1)) {
                $array1[$key] = null;
            }
        }
    }
}
