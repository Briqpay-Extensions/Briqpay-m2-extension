<?php
/**
 * Stand-in for the factory Magento generates for the DB transaction at compile time, so
 * classes that save several objects together can be constructed in unit tests. Tests
 * replace it with a mock; this body is never called.
 */
declare(strict_types=1);

namespace Magento\Framework\DB;

if (!class_exists(TransactionFactory::class, false)) {
    class TransactionFactory
    {
        /**
         * Same signature as the generated factory; never called.
         *
         * @param array<string, mixed> $data
         * @SuppressWarnings("PHPMD.UnusedFormalParameter")
         * @return Transaction
         */
        public function create(array $data = [])
        {
            throw new \LogicException('Generated factory stub must be mocked in tests.');
        }
    }
}
