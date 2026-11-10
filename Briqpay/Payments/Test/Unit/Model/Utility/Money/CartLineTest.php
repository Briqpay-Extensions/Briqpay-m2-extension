<?php
/**
 * Pin the money rules of CartLine::build(), including the
 * worked examples in its docblock. A failure here is a regression in money handling,
 * never a reason to update the expectation.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility\Money;

use Briqpay\Payments\Model\Utility\Money\CartLine;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\Money\CartLine
 */
class CartLineTest extends TestCase
{
    /**
     * @var CartLine
     */
    private CartLine $cartLine;

    protected function setUp(): void
    {
        $this->cartLine = new CartLine();
    }

    public function testWholeQuantityThatDividesEvenly(): void
    {
        $line = $this->cartLine->build(25000, 2, 2500, 'physical', 'SKU-1', 'Item');

        $this->assertSame([
            'productType' => 'physical',
            'reference' => 'SKU-1',
            'name' => 'Item',
            'quantity' => 2,
            'quantityUnit' => 'pc',
            'unitPrice' => 10000,
            'taxRate' => 2500,
            'discountPercentage' => 0,
            'unitPriceIncVat' => 12500,
            'totalAmount' => 25000,
            'totalVatAmount' => 5000,
        ], $line);
    }

    /**
     * A decimal quantity (4.3 kg) is refused.
     */
    public function testADecimalQuantityIsRefused(): void
    {
        // The module sends whole quantities only; GenerateCart refuses such a cart first.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Briqpay takes whole quantities; "SKU-KG" has 4.3.');

        $this->cartLine->build(36496, 4.3, 2500, 'physical', 'SKU-KG', 'Loose goods', 'kg');
    }

    /**
     * A 94.38 line at quantity 50 and 25 % VAT: totalVatAmount is taxRate × unitPrice ×
     * quantity (94375), not totalAmount minus the ex-VAT total (94400).
     */
    public function testTotalVatUsesTheMultiplicationForm(): void
    {
        $line = $this->cartLine->build(471900, 50, 2500, 'physical', 'SKU-50', 'Bulk');

        $this->assertSame(9438, $line['unitPriceIncVat']);
        $this->assertSame(471900, $line['totalAmount']);
        $this->assertSame(7550, $line['unitPrice']);
        $this->assertSame(94375, $line['totalVatAmount']);
        $this->assertNotSame($line['totalAmount'] - $line['unitPrice'] * 50, $line['totalVatAmount']);
    }

    /**
     * CartBalancer docblock: 47190 at quantity 4 needs a non-integer unit price of
     * 11797.5, so no integer unit price reproduces it.
     */
    public function testUnreachableRowTotalAtWholeQuantity(): void
    {
        $line = $this->cartLine->build(47190, 4, 2500, 'physical', 'SKU-4', 'Four');

        $this->assertSame(11798, $line['unitPriceIncVat']);
        $this->assertSame(47192, $line['totalAmount']);
    }

    public function testQuantityOneReproducesAnyAmountExactly(): void
    {
        $line = $this->cartLine->build(4901, 1, 2500, 'shipping_fee', 'shipping', 'Shipping');

        $this->assertSame(1, $line['quantity']);
        $this->assertSame(4901, $line['unitPriceIncVat']);
        $this->assertSame(4901, $line['totalAmount']);
        $this->assertSame(3921, $line['unitPrice']);
        $this->assertSame(980, $line['totalVatAmount']);
    }

    public function testNegativeDiscountLineIsSymmetricWithPositive(): void
    {
        $line = $this->cartLine->build(-2500, 1, 2500, 'discount', 'SKU-1_discount', 'Discount');

        $this->assertSame(-2500, $line['unitPriceIncVat']);
        $this->assertSame(-2500, $line['totalAmount']);
        $this->assertSame(-2000, $line['unitPrice']);
        $this->assertSame(-500, $line['totalVatAmount']);
    }

    public function testZeroQuantityIsTreatedAsOne(): void
    {
        $line = $this->cartLine->build(1000, 0, 0, 'physical', 'SKU-0', 'Zero');

        $this->assertSame(1, $line['quantity']);
        $this->assertSame(1000, $line['totalAmount']);
    }

    public function testZeroTaxRate(): void
    {
        $line = $this->cartLine->build(999, 1, 0, 'surcharge', 'fee', 'Fee');

        $this->assertSame(999, $line['unitPrice']);
        $this->assertSame(0, $line['totalVatAmount']);
    }

    /**
     * buildExact() keeps Magento's total and VAT as given: 4.3 kg at 364.96 stays 364.96,
     * with no remainder for a rounding line to make up.
     */
    public function testExactLineKeepsTheTotalAndVatAsGiven(): void
    {
        $line = $this->cartLine->buildExact(36496, 4, 2500, 7299, 'physical', 'SKU-KG', 'Loose goods', 'kg');

        $this->assertSame(36496, $line['totalAmount']);
        $this->assertSame(7299, $line['totalVatAmount']);
        $this->assertSame(4, $line['quantity']);
        $this->assertSame(9124, $line['unitPriceIncVat']);
        $this->assertSame(7299, $line['unitPrice']);
    }

    public function testExactLineDerivesVatFromTheTotalWhenNotGiven(): void
    {
        $line = $this->cartLine->buildExact(4900, 1, 2500, null, 'shipping_fee', 'shipping', 'Shipping');

        $this->assertSame(4900, $line['totalAmount']);
        $this->assertSame(980, $line['totalVatAmount']);
        $this->assertSame(3920, $line['unitPrice']);
    }

    public function testExactDiscountLineWithoutVat(): void
    {
        $line = $this->cartLine->buildExact(-640, 1, 0, 0, 'discount', 'SKU_discount', 'Discount');

        $this->assertSame(-640, $line['totalAmount']);
        $this->assertSame(0, $line['totalVatAmount']);
        $this->assertSame(-640, $line['unitPrice']);
    }
}
