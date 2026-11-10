<?php
namespace Briqpay\Payments\Model;

use Magento\Payment\Model\InfoInterface;
use Briqpay\Payments\Model\OrderManagement\CaptureOrder;
use Briqpay\Payments\Model\OrderManagement\RefundOrder;
use Magento\Framework\Exception\LocalizedException;
use Briqpay\Payments\Model\CustomTableFactory;
use Briqpay\Payments\Model\OrderManagement\CancelOrder as BriqpayCancelOrder;

/**
 * The Briqpay payment method.
 */
class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    /**
     * Payment Method code
     *
     * @var string
     */
    protected $_code = 'briqpay';

    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial= true;
    // Magento's void would record a void while Briqpay's authorisation stayed open;
    // cancelling the order (CancelOrder) is what releases it.
    protected $_canVoid = false;
    // An order the admin creates is paid later, on Briqpay's hosted payment page: see
    // initialize() and Model\HostedPage\CreatePaymentLink.
    protected $_canUseInternal = true;
    protected $_canCancel = true;
    protected $refundOrder;

    private ?\Magento\Framework\App\State $appState;
    protected $logger;
    protected $captureOrder;
    protected $customTableFactory;
    protected $_request;
    protected $briqpayCancelOrder;
    protected $creditmemoFactory;
    protected $invoiceRepository;
    protected $orderRepository;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\App\RequestInterface $request,
        CaptureOrder $captureOrder,
        CustomTableFactory $customTableFactory,
        RefundOrder $refundOrder,
        BriqpayCancelOrder $briqpayCancelOrder,
        ?\Magento\Framework\App\State $appState = null
    ) {
        $this->appState = $appState;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );
        $this->logger = $logger;
        $this->_request = $request;
        $this->captureOrder = $captureOrder;
        $this->customTableFactory = $customTableFactory;
        $this->refundOrder = $refundOrder;
        $this->briqpayCancelOrder = $briqpayCancelOrder;
    }

    /**
     * The invoiced quantities: from the invoice being captured, which
     * StoreInvoiceForCapture puts on the payment for every invoice however it is
     * created (admin, REST, CLI, other modules). The admin form's request parameters
     * are only a fallback for callers that capture without that event.
     *
     * @param InfoInterface $payment
     * @param \Magento\Sales\Model\Order $order
     * @return array<int, \Magento\Framework\DataObject>
     * @throws LocalizedException
     */
    private function getCaptureCart(InfoInterface $payment, $order): array
    {
        $captureCart = [];
        $invoice = $payment instanceof \Magento\Framework\DataObject
            ? $payment->getData(\Briqpay\Payments\Observer\StoreInvoiceForCapture::PAYMENT_KEY)
            : null;
        if ($invoice) {
            $invoicedQty = [];
            foreach ($invoice->getAllItems() as $invoiceItem) {
                $invoicedQty[(int) $invoiceItem->getOrderItemId()] = (float) $invoiceItem->getQty();
            }
            foreach ($invoice->getAllItems() as $invoiceItem) {
                $orderItem = $invoiceItem->getOrderItem();
                if (!$orderItem || (float) $invoiceItem->getQty() <= 0) {
                    continue;
                }
                // Children are captured through their parent line, except a bundle priced
                // from its children (dynamic price), which is captured through them.
                $parent = $orderItem->getParentItem();
                if ($orderItem->getParentItemId() && !$parent) {
                    continue;
                }
                $throughChildren = $parent
                    ? $parent instanceof \Magento\Sales\Model\Order\Item && $parent->isChildrenCalculated()
                    : $orderItem->isChildrenCalculated();
                if ($parent ? !$throughChildren : $throughChildren) {
                    continue;
                }
                $captureCart[] = new \Magento\Framework\DataObject([
                    'sku' => $orderItem->getSku(),
                    'quote_item_id' => $orderItem->getQuoteItemId(),
                    'qty' => (float) $invoiceItem->getQty(),
                    'charged_incl_vat' => CaptureOrder::chargedInclVat($invoiceItem),
                    'document_amounts' => CaptureOrder::documentAmounts($invoiceItem),
                    'parent_sku' => $parent ? $parent->getSku() : null,
                    'parent_qty' => $parent ? ($invoicedQty[(int) $parent->getItemId()] ?? null) : null,
                ]);
            }

            return $captureCart;
        }

        $items = $this->_request->getParams()['invoice']['items'] ?? null;
        if (!is_array($items)) {
            throw new LocalizedException(__('Briqpay could not determine which items this invoice captures.'));
        }
        foreach ($items as $itemId => $quantity) {
            foreach ($order->getAllVisibleItems() as $item) {
                if ($item->getId() == $itemId) {
                    $item->setQuantity($quantity);
                    $captureCart[] = $item;
                }
            }
        }

        return $captureCart;
    }

    /**
     * An order created in the admin has no Briqpay session yet: Magento places it as
     * "pending payment" and the admin creates a payment link on the order (Briqpay's
     * hosted payment page); the payment webhook moves it on. A storefront order is paid
     * before it exists and is placed as before.
     *
     * @return bool
     */
    public function isInitializeNeeded()
    {
        return $this->inAdmin();
    }

    /**
     * @param string $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     * @return $this
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        // Not Magento's "pending_payment" status, which its cron cancels after a few hours.
        $stateObject->setStatus(\Briqpay\Payments\Setup\Patch\Data\AddAwaitingPaymentStatus::STATUS);
        $stateObject->setIsNotified(false);
        $payment = $this->getData('info_instance');
        if ($payment instanceof \Magento\Payment\Model\InfoInterface) {
            // Marks the order as placed in the admin: it has nothing at Briqpay until
            // its payment link is paid.
            $payment->setAdditionalInformation(\Briqpay\Payments\Model\HostedPage\CreatePaymentLink::ADMIN_ORDER, true);
        }

        return $this;
    }

    private function inAdmin(): bool
    {
        $this->appState = $this->appState
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Framework\App\State::class);
        try {
            return $this->appState->getAreaCode() === \Magento\Framework\App\Area::AREA_ADMINHTML;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Briqpay takes whole quantities only: a cart with a product sold by weight or length
     * (a decimal quantity) is paid with another method.
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote !== null && self::hasDecimalQuantity($quote)) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return bool
     */
    public static function hasDecimalQuantity($quote): bool
    {
        if (!method_exists($quote, 'getAllItems')) {
            return false;
        }
        foreach ((array) $quote->getAllItems() as $item) {
            $qty = (float) $item->getQty();
            if (abs($qty - round($qty)) > 0.000001) {
                return true;
            }
        }

        return false;
    }

    public function capture(InfoInterface $payment, $amount)
    {
        $this->_logger->info('Capture method called.' . $amount);
        $order = $payment->getOrder();
        $this->_logger->info('Order ID: ' . $order->getIncrementId());
        if ($order instanceof \Magento\Sales\Model\Order
            && \Briqpay\Payments\Model\HostedPage\CreatePaymentLink::placedInAdmin($order)
            && \Briqpay\Payments\Model\HostedPage\CreatePaymentLink::unpaid($order)
        ) {
            // Placed in the admin and waiting for its payment link: nothing at Briqpay to capture.
            throw new \Magento\Framework\Exception\LocalizedException(__(
                'This order is waiting for its Briqpay payment link to be paid and cannot be invoiced before then.'
            ));
        }

        $captureCart = $this->getCaptureCart($payment, $order);

        try {
            $order = $payment->getOrder();

            if ($order->getPayment()->getMethod() != 'briqpay') {
                throw new LocalizedException(__('This order is not paid with Briqpay, so Briqpay cannot capture it.'));
            }
            if ($order->getPayment()->getMethod() == 'briqpay') {
                // The invoice's own total is in the order's currency, as the capture is;
                // Magento's $amount is in the base currency.
                $invoice = $payment instanceof \Magento\Framework\DataObject
                    ? $payment->getData(\Briqpay\Payments\Observer\StoreInvoiceForCapture::PAYMENT_KEY)
                    : null;
                $captureId = $this->captureOrder->capture(
                    $order,
                    $captureCart,
                    $invoice ? $invoice->getGrandTotal() : $amount,
                    $invoice !== null
                )['captureId'];
            }
            // Nothing captured (an invoice of fully discounted items): the invoice gets an id of
            // its own, so Magento does not give it the previous capture's id and later credit
            // memos do not point at that capture.
            $payment->setTransactionId($captureId ?? uniqid('briqpay-nothing-captured-', true));
            $payment->setMethod('briqpay');
            $payment->save();
            return $this;
        } catch (LocalizedException $e) {
            $this->_logger->error('Capture refused: ' . $e->getMessage());
            throw $e; // Rethrow the exception to prevent invoice creation
        } catch (\Exception $e) {
            $this->_logger->error('Capture failed: ' . $e->getMessage());
            throw new LocalizedException(__('Error capturing invoice.'));
        }
    }
    public function refund(InfoInterface $payment, $amount)
    {
        $this->_logger->info('Refund Invoice called.');

        try {
            $creditMemo = $payment->getCreditmemo();
            $order = $payment->getOrder();

            if ($order->getPayment()->getMethod() == 'briqpay') {
                $this->refundOrder->refund($creditMemo);
            }

            $payment->setMethod('briqpay');
            $payment->save();

            return $this;
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Already a full sentence for the admin (a refused refund, say).
            $this->_logger->error('Error during refund: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->_logger->error('Error during refund: ' . $e->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(__(
                'The credit memo could not be refunded at Briqpay: %1',
                \Briqpay\Payments\Model\OrderManagement\CaptureOrder::readableError($e)
            ));
        }
    }
}
