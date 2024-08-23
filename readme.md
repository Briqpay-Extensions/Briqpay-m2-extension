# Briqpay Payments Module for Magento 2

![Briqpay Logo](https://cdn.briqpay.com/static/images/briqpayLogo.svg) <!-- Replace with actual logo URL if available -->

## Overview

The Briqpay Payments module for Magento 2 provides seamless integration with the Briqpay payment gateway, enabling merchants to offer Briqpay as a payment method on their Magento stores. This module ensures a smooth and secure payment process, enhancing the shopping experience for your customers.

With Briqpay, you can take advantage of advanced payment features while maintaining full control over the order management process within Magento. The module integrates with Magento's native order management system, ensuring that actions such as creating invoices and credit memos are appropriately communicated to the underlying payment service providers (PSPs).

## Features

- **Seamless Integration**: Easily add Briqpay as a payment method to your Magento 2 store.
- **Native Order Management**: Fully integrates with Magento's order management features, ensuring that all payment actions, including invoicing and refunds, are properly synchronized with Briqpay.
- **Secure Payments**: Leverages Briqpay's secure payment gateway to provide a safe transaction environment for your customers.
- **Customization Options**: Configure and customize the payment method directly from the Magento admin panel.

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

1. Download the latest version of the module from the repository.
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
2. Locate **Briqpay Payments** in the list of available payment methods.
3. Configure the settings according to your requirements, including API credentials, payment action, and environment (sandbox/production).
4. Save the configuration and clear the cache if necessary.

## How It Works

The Briqpay Payments module integrates directly with Magento's native order management system, allowing you to manage your orders with ease:

- **Invoices**: When an invoice is created in Magento, the corresponding payment action is automatically triggered in Briqpay, ensuring that the payment is captured.
- **Credit Memos**: If a refund is issued via a credit memo in Magento, the module communicates with Briqpay to process the refund through the payment gateway.
- **Order Statuses**: The module updates the order statuses in Magento based on the payment events, providing real-time synchronization with Briqpay.

## Support

For any issues, questions, or support requests, please contact us at [support@briqpay.com](mailto:support@briqpay.com).

## License

This module is licensed under the OSL-3.0 OR AFL-3.0 license. See the [LICENSE](LICENSE) file for more information.
