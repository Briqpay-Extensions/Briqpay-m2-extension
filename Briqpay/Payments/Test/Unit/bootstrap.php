<?php
/**
 * Unit-test bootstrap. Runs on the Composer autoloader alone: no Magento install,
 * no database and no DI compilation.
 */
declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// magento/framework autoloads only registration.php, but the module calls __() for
// every exception message.
require_once dirname(__DIR__, 4) . '/vendor/magento/framework/Phrase/__.php';

// Factories Magento would generate at setup:di:compile time.
require_once __DIR__ . '/Fixture/Generated/CustomTableFactory.php';
require_once __DIR__ . '/Fixture/Generated/TransactionFactory.php';
