<?php
/**
 * The cart GenerateCart builds for a Scenarios-style quote, as checkout would send it
 * to Briqpay. Shared by the tests that need a session to work against.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Fixture;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\Utility\GenerateCart;
use Briqpay\Payments\Model\Utility\Money\CartBalancer;
use Briqpay\Payments\Model\Utility\Money\CartLine;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Weee\Helper\Data as WeeeHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckoutCart
{
    /**
     * The cart a scenario's Briqpay session holds after checkout. Capture and refund
     * tests use it as the session read back from Briqpay.
     *
     * @param array<string, mixed> $scenario
     * @return array{cart: array<int, array<string, mixed>>, amountIncVat: int, amountExVat: int}
     */
    public static function cartData(TestCase $test, array $scenario): array
    {
        /** @var CheckoutSession&MockObject $checkoutSession */
        $checkoutSession = (new \PHPUnit\Framework\MockObject\MockBuilder($test, CheckoutSession::class))
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $checkoutSession->method('getQuote')->willReturn(SalesObjects::quote($scenario));

        $weee = $scenario['weee'];
        /** @var WeeeHelper&MockObject $weeeHelper */
        $weeeHelper = (new \PHPUnit\Framework\MockObject\MockBuilder($test, WeeeHelper::class))
            ->disableOriginalConstructor()
            ->onlyMethods([
                'isEnabled',
                'isTaxable',
                'getWeeeTaxAppliedAmount',
                'getWeeeTaxAppliedRowAmount',
                'getRowWeeeTaxInclTax',
            ])
            ->getMock();
        $weeeHelper->method('isEnabled')->willReturn($weee !== []);
        $weeeHelper->method('isTaxable')->willReturn(false);
        $weeeHelper->method('getWeeeTaxAppliedAmount')->willReturnCallback(
            static function ($item) use ($weee) {
                return (float) ($weee[$item->getSku()] ?? 0);
            }
        );
        // Per unit, excl. VAT, as Magento stores it; the row is that times the quantity,
        // and untaxed WEEE is the same incl. VAT.
        $row = static function ($item) use ($weee) {
            return (float) ($weee[$item->getSku()] ?? 0) * (float) $item->getQty();
        };
        $weeeHelper->method('getWeeeTaxAppliedRowAmount')->willReturnCallback($row);
        $weeeHelper->method('getRowWeeeTaxInclTax')->willReturnCallback($row);

        /** @var ImageHelper&MockObject $imageHelper */
        $imageHelper = (new \PHPUnit\Framework\MockObject\MockBuilder($test, ImageHelper::class))
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Logger&MockObject $logger */
        $logger = (new \PHPUnit\Framework\MockObject\MockBuilder($test, Logger::class))
            ->disableOriginalConstructor()
            ->getMock();
        $cartLine = new CartLine();

        $generateCart = new GenerateCart(
            $checkoutSession,
            $logger,
            $weeeHelper,
            $imageHelper,
            $cartLine,
            new CartBalancer($logger, $cartLine)
        );

        return $generateCart->getCartData();
    }
}
