<?php

namespace Briqpay\Payments\Model\Utility;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\Data\AddressInterface;
use Briqpay\Payments\Logger\Logger;

class AssignBillingAddress
{
    protected $checkoutSession;
    protected $customerRepository;
    protected $cartRepository;
    protected $logger;

    public function __construct(
        CheckoutSession $checkoutSession,
        CustomerRepositoryInterface $customerRepository,
        CartRepositoryInterface $cartRepository,
        Logger $logger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->customerRepository = $customerRepository;
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
    }

    public function getBillingData($fallbackEmail = null)
    {
        $quoteId = $this->checkoutSession->getQuoteId();
        if (!$quoteId) {
            $this->logger->debug('No Quote ID found in session.');
            return null;
        }
        try {
            $quote = $this->cartRepository->getActive($quoteId);
        } catch (LocalizedException $e) {
            $this->logger->error('LocalizedException: ' . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Exception: ' . $e->getMessage());
            return null;
        }

        return $this->getBillingDataFor($quote, $fallbackEmail);
    }

    /**
     * The billing prefill of a given quote (the checkout's, or an admin order's).
     *
     * @param mixed $quote
     * @param string|null $fallbackEmail
     * @return array<string, string>|null
     */
    public function getBillingDataFor($quote, $fallbackEmail = null)
    {
        try {

            $billingAddress = $quote->getBillingAddress();
            if (!$billingAddress) {
                $this->logger->debug('No Billing Address found.');
                return null;
            }

            if (!($billingAddress instanceof AddressInterface)) {
                if ($billingAddress instanceof \Magento\Quote\Model\Quote\Address) {
                    $billingAddress = $billingAddress->getDataModel();
                    if (!($billingAddress instanceof AddressInterface)) {
                        $this->logger->debug('Failed to cast Billing Address to AddressInterface.');
                        return null;
                    }
                } else {
                    return null;
                }
            }

            $region = '';
            $regionObject = $billingAddress->getRegion();
            if ($regionObject && method_exists($regionObject, 'getRegion')) {
                $region = $regionObject->getRegion() ?? "";
            }

            $phone = $billingAddress->getTelephone() ?? "";

            $streetLines = $billingAddress->getStreet();
            $streetAddress1 = isset($streetLines[0]) ? $streetLines[0] : '';
            $streetAddress2 = isset($streetLines[1]) ? $streetLines[1] : '';

            $customerEmail = $quote->getBillingAddress()->getEmail();
            if (!$customerEmail) {
                $customerEmail = $quote->getCustomerEmail();
                $customerId = $quote->getCustomerId();
            
                if ($customerId) {
                    try {
                        $customer = $this->customerRepository->getById($customerId);
                        $customerEmail = $customer->getEmail();
                    } catch (\Exception $e) {
                        $this->logger->error('Error loading customer data: ' . $e->getMessage());
                    }
                }
            }
            
            // The checkout's email for a guest is the latest one typed; the quote's can be
            // older when its save is still under way.
            if ($fallbackEmail && !$quote->getCustomerId()) {
                $customerEmail = $fallbackEmail;
                if (!$quote->getCustomerEmail()) {
                    $quote->setCustomerEmail($fallbackEmail);
                }
            }
            // Always fallback to the provided fallbackEmail if customerEmail is still null
            if (is_null($customerEmail)) {
                $customerEmail = $fallbackEmail;
                $this->logger->debug('Using fallback email for billing: ');
                $quote->setCustomerEmail($customerEmail);
            }

            $billingData = [
                'streetAddress' => $streetAddress1,
                'streetAddress2' => $streetAddress2,
                'zip' => $billingAddress->getPostcode(),
                'city' => $billingAddress->getCity(),
                'region' => $region,
                'firstName' => $billingAddress->getFirstname(),
                'lastName' => $billingAddress->getLastname(),
                'email' => $customerEmail,
                'phoneNumber' => $phone,
                // Without it Briqpay stores the session's country as the billing country.
                'country' => (string) ($billingAddress->getCountryId() ?? ''),
            ];

            // Replace null values with empty strings
            foreach ($billingData as $key => $value) {
                if (is_null($value)) {
                    $billingData[$key] = '';
                }

                // Region is only used in some countries, so an empty one is not an error.
                if (empty($value) && !in_array($key, ["streetAddress2", "region"], true)) {
                    $this->logger->info('Empty value found for billing data field: ' . $key);
                }
            }

            return $billingData;
        } catch (LocalizedException $e) {
            $this->logger->error('LocalizedException: ' . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Exception: ' . $e->getMessage());
            return null;
        }
    }
}
