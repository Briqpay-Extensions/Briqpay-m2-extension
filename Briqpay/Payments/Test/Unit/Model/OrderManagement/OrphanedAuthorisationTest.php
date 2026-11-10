<?php
/**
 * A paid authorisation is cancelled at Briqpay only when Magento certainly cannot create
 * its order, and the quote is then freed for a new session under a new order number.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Model\OrderManagement;

use Briqpay\Payments\Logger\Logger;
use Briqpay\Payments\Model\OrderManagement\OrphanedAuthorisation;
use Briqpay\Payments\Model\Utility\OrderBlockers;
use Briqpay\Payments\Rest\ApiClient;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Model\OrderManagement\OrphanedAuthorisation
 * @uses \Briqpay\Payments\Model\OrderManagement\SessionLedger
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class OrphanedAuthorisationTest extends TestCase
{
    private const SESSION_ID = 'test-session-0001';

    /**
     * @var ApiClient&MockObject
     */
    private $apiClient;

    /**
     * @var CartRepositoryInterface&MockObject
     */
    private $quoteRepository;

    /**
     * @var array<int, string>
     */
    private array $blockers;

    /**
     * Orders found per lookup field.
     *
     * @var array<string, array<int, Order>>
     */
    private array $orders;

    /**
     * @var string field of the last lookup
     */
    private string $lookupField = '';

    /**
     * @var Quote&MockObject
     */
    private $quote;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(ApiClient::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->blockers = ['Item cannot be ordered: SKU-GONE This product is out of stock.'];
        $this->orders = [];
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIsActive'])
            ->getMock();
        $this->quote->method('getId')->willReturn(42);
        $this->quote->setData(['briqpay_session_id' => self::SESSION_ID, 'reserved_order_id' => '000000089']);
    }

    public function testAuthorisationNoOrderCanBeCreatedForIsCancelledAndTheQuoteFreed(): void
    {
        $this->quote->method('getIsActive')->willReturn(true);
        $this->apiClient->expects(self::once())->method('request')
            ->with('POST', '/v3/session/' . self::SESSION_ID . '/order/cancel', ['data' => []])
            ->willReturn([]);
        $this->quoteRepository->expects(self::once())->method('save')->with($this->quote);

        self::assertTrue($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $this->session()));
        self::assertNull($this->quote->getData('briqpay_session_id'));
        self::assertNull($this->quote->getData('reserved_order_id'));
    }

    public function testConvertedQuoteIsLeftAlone(): void
    {
        $this->quote->method('getIsActive')->willReturn(false);

        $this->expectNothingCancelled();
        self::assertFalse($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $this->session()));
    }

    public function testSessionWithAnOrderIsLeftAlone(): void
    {
        $this->quote->method('getIsActive')->willReturn(true);
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $this->orders = ['briqpay_session_id' => [$order]];

        $this->expectNothingCancelled();
        self::assertFalse($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $this->session()));
    }

    /**
     * An order created for the quote may not carry the session id yet.
     */
    public function testQuoteWithAnOrderIsLeftAlone(): void
    {
        $this->quote->method('getIsActive')->willReturn(true);
        $this->orders = ['quote_id' => [(new \ReflectionClass(Order::class))->newInstanceWithoutConstructor()]];

        $this->expectNothingCancelled();
        self::assertFalse($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $this->session()));
    }

    public function testSessionWithACaptureIsLeftAlone(): void
    {
        $this->quote->method('getIsActive')->willReturn(true);
        $session = $this->session();
        $session['data']['captures'] = [['captureId' => 'test-capture-0001', 'status' => 'approved']];

        $this->expectNothingCancelled();
        self::assertFalse($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $session));
    }

    public function testQuoteMagentoWouldAcceptIsLeftAlone(): void
    {
        $this->quote->method('getIsActive')->willReturn(true);
        $this->blockers = [];

        $this->expectNothingCancelled();
        self::assertFalse($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $this->session()));
    }

    public function testPaymentMethodThatCannotCancelLeavesTheQuoteAsItIs(): void
    {
        $this->quote->method('getIsActive')->willReturn(true);
        $this->apiClient->method('request')
            ->willReturn(['error' => \Briqpay\Payments\Rest\ApiClient::CANCEL_NOT_SUPPORTED]);
        $this->quoteRepository->expects(self::never())->method('save');

        self::assertFalse($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $this->session()));
        self::assertSame(self::SESSION_ID, $this->quote->getData('briqpay_session_id'));
    }

    public function testFailedCancelLeavesTheQuoteAsItIs(): void
    {
        $this->quote->method('getIsActive')->willReturn(true);
        $this->apiClient->method('request')->willThrowException(new \RuntimeException('timeout'));
        $this->quoteRepository->expects(self::never())->method('save');

        self::assertFalse($this->orphaned()->cancelIfNoOrderPossible($this->quote, self::SESSION_ID, $this->session()));
    }

    private function expectNothingCancelled(): void
    {
        $this->apiClient->expects(self::never())->method('request');
        $this->quoteRepository->expects(self::never())->method('save');
    }

    /**
     * @return array<string, mixed>
     */
    private function session(): array
    {
        return ['sessionId' => self::SESSION_ID, 'status' => 'completed', 'data' => ['captures' => []]];
    }

    private function orphaned(): OrphanedAuthorisation
    {
        $blockers = $this->createMock(OrderBlockers::class);
        $blockers->method('forQuote')->willReturnCallback(function (): array {
            return $this->blockers;
        });

        $results = $this->createMock(OrderSearchResultInterface::class);
        $results->method('getItems')->willReturnCallback(function (): array {
            return $this->orders[$this->lookupField] ?? [];
        });
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($results);

        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnCallback(
            function (string $field) use (&$criteriaBuilder) {
                $this->lookupField = $field;

                return $criteriaBuilder;
            }
        );
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return new OrphanedAuthorisation(
            $this->apiClient,
            $blockers,
            $this->quoteRepository,
            $orderRepository,
            $criteriaBuilder,
            $this->createMock(Logger::class)
        );
    }
}
