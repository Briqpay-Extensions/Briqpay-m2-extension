# Briqpay Payments Module for Magento 2

![Briqpay Logo](https://cdn.briqpay.com/static/images/briqpayLogo.svg)

## Overview

The Briqpay Payments module for Magento 2 provides seamless integration with the Briqpay payment gateway, enabling merchants to offer Briqpay as a payment method on their Magento stores. This module ensures a smooth and secure payment process, enhancing the shopping experience for your customers.

With Briqpay, you can take advantage of advanced payment features while maintaining full control over the order management process within Magento. The module integrates with Magento's native order management system, ensuring that actions such as creating invoices and credit memos are appropriately communicated to the underlying payment service providers (PSPs).

## Features

- **Seamless Integration**: Easily add Briqpay as a payment method to your Magento 2 store.
- **Native Order Management**: Fully integrates with Magento's order management features, ensuring that all payment actions, including invoicing and refunds, are properly synchronized with Briqpay.
- **Secure Payments**: Leverages Briqpay's secure payment gateway to provide a safe transaction environment for your customers.
- **Customization Options**: Configure and customize the payment method directly from the Magento admin panel.

## Requirements

| | |
| :-- | :-- |
| Magento Open Source / Adobe Commerce | 2.4.6 or later |
| PHP | 8.2, 8.3, 8.4 or 8.5 |
| Briqpay | a merchant account with V3 API credentials |

Stores on PHP 8.1 stay on the 1.x line of the module until PHP is updated.

Briqpay takes whole quantities only: a cart with a product sold in decimal quantities
(by weight or length) is not offered Briqpay as a payment method.

## Installation

### 1. Install via Composer

To install the Briqpay Payments module, use Composer. Run the following commands in your Magento 2 root directory:

```bash
composer require briqpay/module-payments
bin/magento module:enable Briqpay_Payments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

### 2. Manual Installation

1. Download `BriqpayPayments-<version>.zip` from the latest release on GitHub (not the
   "Source code" archive, which nests the module one level deeper).
2. Extract the contents of the zip file into the `app/code/Briqpay/Payments` directory of your Magento installation.
3. Enable the module and run the Magento setup commands:

```bash
bin/magento module:enable Briqpay_Payments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

## Configuration

After installation, the Briqpay payment method can be configured in the Magento admin panel:

1. Go to **Stores > Configuration > Sales > Payment Methods**.
2. Open **Briqpay** in the list of payment methods.
3. Enter the client ID and client secret from Briqpay (the secret is stored encrypted), choose
   test mode (the Briqpay playground) or live, and the checkout type (consumer or business).
4. Save the configuration and flush the cache.

**Orders created in the admin** can be paid with Briqpay: choose Briqpay when creating the
order, then on the order view click *Create payment link* and send the customer the link
to Briqpay's payment page. After paying, the customer lands on a confirmation page in the
shop, and the order moves on (normally to Processing) when Briqpay's webhook arrives. *Update payment
link* sends the order's current details to Briqpay again; the link stays the same while it
can still be paid. A link for the other customer type is a new link, and a payment made on
the earlier one is reported on the order. An order whose link was never paid can be
cancelled or edited as usual, but not invoiced; the link itself stays open at Briqpay.

Webhooks need no setup at Briqpay: every session the module creates tells Briqpay the
shop's own webhook addresses, per store view.

### Upgrading from 1.x

Run `composer require briqpay/module-payments:^2.0 && bin/magento setup:upgrade`. PHP 8.2 or
later and Magento 2.4.6 or later are required. The upgrade rewrites the sales order table
once (the Briqpay session id column becomes an indexed varchar), so run it at a quiet
moment on a large store. The upgrade keeps every order, invoice and
credit memo; the stored client secret is encrypted; the "Strict rounding" setting and the
"Void" action are gone (cancelling an order releases its authorisation). Downgrading to 1.x
afterwards needs the client secret entered again.

## How It Works

The Briqpay Payments module integrates directly with Magento's native order management system, allowing you to manage your orders with ease:

- **Invoices**: When an invoice is created in Magento, the corresponding payment action is automatically triggered in Briqpay, ensuring that the payment is captured.
- **Credit Memos**: If a refund is issued via a credit memo in Magento, the module communicates with Briqpay to process the refund through the payment gateway.
- **Order Statuses**: The module updates the order statuses in Magento based on the payment events, providing real-time synchronization with Briqpay.

## Development

```bash
composer update        # installs Magento packages from the Mage-OS mirror; no Adobe keys needed

composer test:unit     # PHPUnit
composer cs            # Magento 2 coding standard + PHP 8.2-8.5 compatibility
composer stan          # PHPStan
composer md            # PHP Mess Detector
composer check         # all of the above, plus a syntax lint
```

The unit suite runs on plain PHP with the Composer autoloader: no Magento
installation, database or `setup:di:compile` is needed.

The tests under `Briqpay/Payments/Test/Unit` pin what the module sends to Briqpay for
checkout carts, and check capture and refund carts against what Briqpay holds. A test
that disagrees means the money calculation changed: fix the code, not the test.

## Support

For any issues, questions, or support requests, please contact us at [support@briqpay.com](mailto:support@briqpay.com).

## License

This module is licensed under the OSL-3.0 OR AFL-3.0 license. See the [LICENSE](LICENSE) file for more information.
