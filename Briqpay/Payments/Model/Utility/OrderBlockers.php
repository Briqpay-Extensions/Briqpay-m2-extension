<?php
/**
 * Why Magento would refuse to turn a quote into an order: the check behind the Briqpay
 * decision, and behind cancelling an authorisation no order can be created for.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\Utility;

class OrderBlockers
{
    /**
     * @var \Magento\Quote\Model\QuoteValidator
     */
    private \Magento\Quote\Model\QuoteValidator $quoteValidator;

    /**
     * @param \Magento\Quote\Model\QuoteValidator $quoteValidator
     */
    public function __construct(\Magento\Quote\Model\QuoteValidator $quoteValidator)
    {
        $this->quoteValidator = $quoteValidator;
    }

    /**
     * Why Magento would refuse to turn the quote into an order.
     *
     * The purchase is then stopped before it is paid rather than paid and then left
     * without an order.
     *
     * Setting an item's quantity runs Magento's own stock validation for it (the
     * inventory modules listen to that event), the same check the cart page shows.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array<int, string>
     */
    public function forQuote($quote): array
    {
        $blockers = [];
        foreach ($quote->getAllItems() as $item) {
            $item->setQty($item->getQty());
            if ($item->getHasError()) {
                $blockers[] = 'Item cannot be ordered: ' . $item->getSku() . ' ' . implode(' ', array_map(
                    static function ($message): string {
                        return (string) $message;
                    },
                    (array) $item->getMessage(false)
                ));
            }
        }
        if ($quote->getHasError() && $blockers === []) {
            $blockers[] = 'Quote has errors.';
        }
        if (!$quote->validateMinimumAmount()) {
            $blockers[] = 'Quote is below the minimum order amount.';
        }
        // The checks Magento runs before placing the order: addresses, shipping method,
        // payment method. A virtual quote with no billing address saved yet fails here.
        try {
            $this->quoteValidator->validateBeforeSubmit($quote);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $blockers[] = 'Magento cannot place the order: ' . $e->getMessage();
        }

        return $blockers;
    }
}
