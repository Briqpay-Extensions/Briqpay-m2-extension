<?php
declare(strict_types=1);

namespace Briqpay\Payments\Model\Utility;

use Briqpay\Payments\Logger\Logger;

/**
 * What a Briqpay session writes on its Magento order: the payment method the customer
 * chose, the reservation, the session and its status, the backoffice link, and the
 * company for a business purchase. Used when an order is created for a paid session
 * and when a session paid later (a payment link) completes an existing order.
 */
class SessionOrderData
{
    private Logger $logger;

    private ScopeHelper $scopeHelper;

    public function __construct(Logger $logger, ScopeHelper $scopeHelper)
    {
        $this->logger = $logger;
        $this->scopeHelper = $scopeHelper;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param array<string, mixed> $sessionData the session as Briqpay returns it
     * @return void
     */
    public function apply($order, array $sessionData): void
    {


        // Extract transactions
        $transactions = $sessionData['data']['transactions'] ?? [];
        $pspMetaData = $sessionData['data']['pspMetadata'] ?? [];
        $underlyingPspName = (string) ($pspMetaData['description'] ?? '');
        $pspProviderName = (string) ($transactions[0]['pspIntegrationName'] ?? '');
        $pspDisplayName = (string) ($transactions[0]['pspDisplayName'] ?? '');
        $pspReservationId = (string) ($transactions[0]['reservationId'] ?? '');
        $briqpaySessionStatus = (string) ($transactions[0]['status'] ?? '');
        $secondaryReference = $transactions[0]['secondaryReservationId'] ?? '';

        // Set PSP display name
        if ($pspDisplayName !== '') {
            $order->setData('briqpay_psp_display_name', $pspDisplayName);
        } else {
            $this->logger->debug('PSP Display Name is empty.');
        }

        // Set reservation ID
        if ($pspReservationId !== '') {
            $order->setData('briqpay_psp_reservationId', $pspReservationId);
        } else {
            $this->logger->debug('PSP Reservation ID is empty.');
        }

        // Set session ID
        $sessionId = $sessionData['sessionId'] ?? '';
        if ($sessionId !== '') {
            $order->setData('briqpay_session_id', $sessionId);
            $this->logger->info('Set briqpay_session_id to ' . $sessionId);
        } else {
            $this->logger->info('Session ID is empty.');
        }

        // The order's backoffice link, with the merchant id from the session's client token.
        $merchantId = '';
        if (isset($sessionData['clientToken'])) {
            $jwt = $sessionData['clientToken'];
            $jwtExplode = explode('.', $jwt);
            if (isset($jwtExplode[1])) {
                $decodedArray = json_decode(base64_decode($jwtExplode[1]), true);
                $merchantId = $decodedArray['merchantId'] ?? '';
            } else {
                $this->logger->warning('JWT does not contain expected payload.');
            }
        } else {
            $this->logger->warning('Client token not found in session data.');
        }

        if ($merchantId !== '' && $sessionId !== '') {
            $testmode = $this->scopeHelper->getScopedConfigValue('payment/briqpay/test_mode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $backofficeUrl = 'https://app.briqpay.com/dashboard/sessions/orders/' . $sessionId . '?test=' . $testmode . '&merchantId=' . $merchantId;
            $order->setData('briqpay_backoffice_url', $backofficeUrl);
        } else {
            $this->logger->warning('Cannot construct backoffice URL, merchantId or sessionId is empty.');
        }

        $order->setData('briqpay_psp_provider', $pspProviderName);
        $order->setData('briqpay_psp_underlying_payment_method', $underlyingPspName);
        $order->setData('briqpay_secondary_reservationId', $secondaryReference);

        // Set session status
        if ($briqpaySessionStatus !== '') {
            $order->setData('briqpay_session_status', $briqpaySessionStatus);
        } else {
            $this->logger->warning('Briqpay Session Status is empty.');
        }

        // Set business-specific data
        if (($sessionData['customerType'] ?? null) === 'business' && isset($sessionData['data']['company'])) {
            $company = $sessionData['data']['company'];
            $companyCin = $company['cin'] ?? null;
            $companyName = $company['name'] ?? null;
            $companyVatno = $company['vatNumber'] ?? null;



            if ($companyCin !== null) {
                $order->setData('briqpay_cin', $companyCin);
            }
            if ($companyName !== null) {
                $order->setData('briqpay_company_name', $companyName);
            }
            if ($companyVatno !== null) {
                $order->setData('briqpay_company_vatNo', $companyVatno);
            }
        } else {
            $this->logger->warning('Customer is not a business or company data not found.');
        }

        // Set strongAuth data
        if (isset($sessionData['data']['strongAuth'])) {
            $strongAuth = $sessionData['data']['strongAuth'];
            if (isset($strongAuth['output']) && isset($strongAuth['provider'])) {
                $extraDataStrongAuth = base64_encode(json_encode($strongAuth));
                $order->setData('briqpay_strong_auth', $extraDataStrongAuth);
            } else {
                $this->logger->error('Missing required fields in strongAuth.');
            }
        } else {
            $this->logger->info('No strongAuth found.');
        }

        // Log end of function
        $this->logger->info('Finished setOrderAdditionalData function.');
        }
}
