<?php
/**
 * Stand-in for the factory Magento generates for CustomTable at compile time, so the
 * classes that depend on it can be constructed in unit tests. Tests replace it with a
 * mock; this body is never called.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model;

if (!class_exists(CustomTableFactory::class, false)) {
    class CustomTableFactory
    {
        /**
         * Same signature as the generated factory; never called.
         *
         * @param array<string, mixed> $data
         * @SuppressWarnings("PHPMD.UnusedFormalParameter")
         * @return CustomTable
         */
        public function create(array $data = [])
        {
            throw new \LogicException('Generated factory stub must be mocked in tests.');
        }
    }
}
