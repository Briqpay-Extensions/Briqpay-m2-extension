<?php
/**
 * What stops a quote from becoming an order is found before the customer pays
 * (the decision) and after, when the authorisation is cancelled for it.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\Utility;

use Briqpay\Payments\Model\Utility\OrderBlockers;
use Magento\Framework\Exception\ValidatorException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\Utility\OrderBlockers
 */
class OrderBlockersTest extends TestCase
{
    /**
     * A virtual cart whose billing address was never saved: Magento refuses it at
     * submit, so the decision must refuse it first.
     */
    public function testMagentoPreSubmitValidationFailureIsABlocker(): void
    {
        $validator = $this->createMock(QuoteValidator::class);
        $validator->method('validateBeforeSubmit')->willThrowException(
            new ValidatorException(__('Please check the billing address information. %1', 'firstname is required'))
        );

        $blockers = (new OrderBlockers($validator))->forQuote($this->quote());

        $this->assertSame(
            ['Magento cannot place the order: Please check the billing address information. firstname is required'],
            $blockers
        );
    }

    public function testValidQuoteHasNoBlockers(): void
    {
        $validator = $this->createMock(QuoteValidator::class);
        $validator->expects($this->once())->method('validateBeforeSubmit');

        $this->assertSame([], (new OrderBlockers($validator))->forQuote($this->quote()));
    }

    private function quote(): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllItems', 'validateMinimumAmount'])
            ->getMock();
        $quote->method('getAllItems')->willReturn([]);
        $quote->method('validateMinimumAmount')->willReturn(true);

        return $quote;
    }
}
