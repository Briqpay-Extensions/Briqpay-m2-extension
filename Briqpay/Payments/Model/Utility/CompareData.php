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

        // Ignore 'country' field
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
     * Compares session and qoute grand total.
     *
     * @param int $sessionTotal
     * @param int $qouteGrandTotal
     * @return bool
     */
    public function doesTotalsMatch(int $sessionTotal, int $qouteGrandTotal): bool
    {
        if ((int)$sessionTotal == (int)$qouteGrandTotal) {
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
