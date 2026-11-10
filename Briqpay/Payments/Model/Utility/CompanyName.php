<?php
declare(strict_types=1);

namespace Briqpay\Payments\Model\Utility;

/**
 * The company names a business session carries, read from the quote's addresses.
 */
class CompanyName
{
    /** The session reference that keeps the company name a business session was created with. */
    public const REFERENCE = 'companyName';

    /**
     * The name as compared: case and spacing do not make another company.
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure string transform, never intercepted
    public static function normalise(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Whether the cart's billing company is another than the business session's. The
     * session's is the name it was created with (its reference), or, for a session from
     * before that reference, the company block or the billing company name. The company
     * block Briqpay returns may differ from the name sent, which is not a change of the
     * cart. A cart that names no company has not changed it.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array<string, mixed> $session the session as Briqpay returns it
     * @return bool
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure comparison, never intercepted
    public static function changedSince($quote, array $session): bool
    {
        $current = self::normalise(self::fromQuote($quote)['billing']);
        $sessionCompany = self::normalise((string) ($session['references'][self::REFERENCE]
            ?? $session['data']['company']['name']
            ?? $session['data']['billing']['companyName']
            ?? ''));

        return $current !== '' && $current !== $sessionCompany;
    }

    /**
     * The billing and the shipping company. A guest enters the billing address after
     * the shipping address, at the payment step, so an empty billing company is the
     * shipping company. A virtual cart has no shipping address the customer sees, so
     * only the billing company counts there.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array{billing: string, shipping: string}
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure read of the quote, never intercepted
    public static function fromQuote($quote): array
    {
        $billing = trim((string) ($quote->getBillingAddress() ? $quote->getBillingAddress()->getCompany() : ''));
        $shipping = $quote->getIsVirtual() || !$quote->getShippingAddress()
            ? ''
            : trim((string) $quote->getShippingAddress()->getCompany());

        return ['billing' => $billing === '' ? $shipping : $billing, 'shipping' => $shipping];
    }

    /**
     * Adds the company names to the billing and shipping blocks of a session body.
     *
     * @param array<string, mixed> $data the session body's data
     * @param array{billing: string, shipping: string} $names
     * @return array<string, mixed>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure array transform, never intercepted
    public static function onAddresses(array $data, array $names): array
    {
        foreach (['billing', 'shipping'] as $block) {
            if ($names[$block] !== '' && is_array($data[$block] ?? null)) {
                $data[$block]['companyName'] = $names[$block];
            }
        }

        return $data;
    }
}
